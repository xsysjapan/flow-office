<?php

namespace App\Console\Commands;

use App\Console\Attributes\AdminExecutable;
use App\Console\CurrentAdminCommandRun;
use App\Domain\EventSourcing\Rebuild\UserSettingsAtReplayMigrator;
use App\Domain\EventSourcing\Rebuild\ReadModelRebuilder;
use App\Services\EventSourcing\ReadModelRebuildRunService;
use Illuminate\Console\Command;
use Throwable;

/**
 * 過去の利用者の作成系イベントに、タイムゾーン・テナントIDを書き足し、ReadModelを作り直す運用コマンド
 * (docs/changesets/20261124-user-projection-settings-at-replay/spec.md 論点2)。
 *
 * - 既定は試し実行(書き足す件数・テナントIDの上書きの一覧・ライブの値と設定の履歴の値が違う利用者・補えない値を出すだけで、何も書かない)。
 *   `--execute`で、バックアップ表を作ったうえで1つのトランザクションで「書き換え → 補正ログ → ReadModelの作り直し → 検証」を行う。失敗したら全て戻る(バックアップ表は残る)。
 * - 補えない値が1つでもあると、`--execute`は何も変えずに止まる。一括操作の取消イベントが未補正のときは、`events:fix-rebuild-determinism`を案内して止まる。
 * - 記録・心拍・メンテナンス・排他は`read-models:rebuild`と同じ仕組みを使う。
 */
#[AdminExecutable(label: '利用者の設定値の補正')]
class FixUserSettingsAtReplayCommand extends Command
{
    protected $signature = 'events:fix-user-settings-at-replay
        {--execute : 書き換えを実行する(省略時は試し実行で、何も変更しない)}';

    protected $description = '過去の利用者の作成系イベントにタイムゾーン・テナントIDを書き足し、ReadModelを作り直して検証する(1つのトランザクション)。'
        .'既定は試し実行(件数・上書きの一覧を出すだけ)。--execute で実行する。実行中は全利用者がメンテナンス中(全API 503)になる。'
        .'日次バッチの時刻(1:00〜2:20)を避けて実行すること。';

    public function handle(UserSettingsAtReplayMigrator $migrator, ReadModelRebuildRunService $runService): int
    {
        $execute = (bool) $this->option('execute');
        $dryRun = ! $execute;
        $startedAt = microtime(true);

        $run = CurrentAdminCommandRun::get();
        $direct = $run === null;
        if ($direct) {
            $run = $runService->startDirectRun($dryRun, ReadModelRebuildRunService::FIX_USER_SETTINGS_COMMAND_NAME);
            if ($run === null) {
                $this->error($runService->isRunning()
                    ? ReadModelRebuildRunService::REBUILD_ACTIVE_MESSAGE
                    : ReadModelRebuildRunService::ASSESSMENT_ACTIVE_MESSAGE);

                return self::FAILURE;
            }
        } elseif ($runService->isOtherMaintenanceRunning($run)) {
            $this->error(ReadModelRebuildRunService::REBUILD_ACTIVE_MESSAGE);
            $run->update(['error_message' => ReadModelRebuildRunService::REBUILD_ACTIVE_MESSAGE]);

            return self::FAILURE;
        }

        try {
            ReadModelRebuilder::heartbeat();
            $wait = (int) config('read_model_rebuild.claim_wait_seconds', 60);
            // 実行中の書き込み(他のリクエスト)が済むのを待つのは、書き込む本実行だけ。
            if ($execute && $wait > 0) {
                sleep($wait);
            }

            $summary = $migrator->migrate($dryRun, $startedAt);
        } catch (Throwable $e) {
            report($e);
            $backup = $migrator->backupTable();
            $message = '補正を実行できませんでした: '.$e->getMessage()
                .($backup !== null ? " ロールバックしたため、stored_events・ReadModelは変わっていません(バックアップ表 {$backup} は残っています)。" : ' 何も変更していません。');
            $this->error($message);
            $run->update(['error_message' => mb_substr($message, 0, 2000)]);
            ReadModelRebuilder::clearHeartbeat();
            if ($direct) {
                $runService->markFailed($run, $message);
            }

            return self::FAILURE;
        }

        $run->update(['summary' => $summary]);
        ReadModelRebuilder::clearHeartbeat();
        if ($direct) {
            $runService->markSucceeded($run, $summary);
        }

        $this->printResult($summary);

        return self::SUCCESS;
    }

    /** @param  array<string, mixed>  $summary */
    private function printResult(array $summary): void
    {
        self::printSummary($this, $summary);
    }

    /**
     * 補正の結果を出力する(まとめのコマンドからも使う)。
     *
     * @param  array<string, mixed>  $summary
     */
    public static function printSummary(Command $command, array $summary): void
    {
        if ($summary['unresolved'] !== []) {
            $command->warn('補えない値: '.count($summary['unresolved']).' 件。--execute はこの値がある間エラーで止まります。人が確認してください。');
            foreach ($summary['unresolved'] as $row) {
                $command->line("  - stored_events.id={$row['event_id']} {$row['event_class']}: {$row['reason']}");
            }
        }
        if ($summary['status'] === 'nothing') {
            $command->info('対象なし(タイムゾーン・テナントIDが未補正の利用者イベントが無い)。');

            return;
        }

        $command->info(($summary['dry_run'] ? '試し実行(データは変更していません。--execute で実行します)' : '書き換えました')
            .": イベント {$summary['event_count']} 件(タイムゾーン {$summary['timezone_filled']} 件・テナントID補完 {$summary['tenant_filled']} 件(うち null を明示 {$summary['tenant_filled_null']} 件)・テナントID上書き {$summary['tenant_overwrite_count']} 件)");
        if ($summary['tenant_overwrite_count'] > 0) {
            $command->warn('テナントIDが null で記録され、ライブが null 以外のイベント(ライブの値で上書きします):');
            foreach ($summary['tenant_overwrites'] as $row) {
                $command->line("  - stored_events.id={$row['event_id']} {$row['event_class']} entraUserId={$row['entra_user_id']} null → {$row['live']}");
            }
        }
        if ($summary['timezone_difference_count'] > 0) {
            $command->line('ライブの値と設定の履歴から復元した値が違う利用者(参考。ライブの値を入れます): '.$summary['timezone_difference_count'].' 件');
            foreach ($summary['timezone_differences'] as $row) {
                $command->line("  - stored_events.id={$row['event_id']} 利用者={$row['user_id']} ライブ={$row['live']} 履歴={$row['history']}");
            }
        }
        $command->table(['stored_events.id', 'event_class', '変更'], array_map(
            fn (array $row) => [$row['event_id'], $row['event_class'], implode(' / ', $row['changes'])],
            $summary['sample'],
        ));
        if ($summary['event_count'] > count($summary['sample'])) {
            $command->line('(先頭 '.count($summary['sample']).' 件のみ表示)');
        }
        if ($summary['backup_table'] !== null) {
            $command->line("バックアップ表: {$summary['backup_table']}");
        }
        if ($summary['dry_run']) {
            return;
        }

        $command->info('補正ログ(stored_event_corrections)に補正キー '.UserSettingsAtReplayMigrator::CORRECTION_KEY.' で記録しました。検証に成功しました。');
        if (isset($summary['changed_tables'])) {
            $command->info('ReadModelの作り直し: 変更のあったテーブル'.$summary['changed_tables'].'件(0件が合格)');
            $rows = $summary['tables'];
            usort($rows, fn (array $a, array $b) => [$b['changed'], $a['table']] <=> [$a['changed'], $b['table']]);
            $command->table(
                ['テーブル', '前', '後', '変更'],
                array_map(fn (array $row) => [$row['table'], $row['before'], $row['after'], $row['changed'] ? '変更あり' : ''], $rows),
            );
        }
    }
}
