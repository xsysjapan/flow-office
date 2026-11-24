<?php

namespace App\Console\Commands;

use App\Console\Attributes\AdminExecutable;
use App\Console\CurrentAdminCommandRun;
use App\Domain\EventSourcing\Rebuild\CalendarBulkOperationTargetIdMigrator;
use App\Domain\EventSourcing\Rebuild\RebuildDeterminismMigrator;
use App\Domain\EventSourcing\Rebuild\ReadModelRebuilder;
use App\Services\EventSourcing\ReadModelRebuildRunService;
use Illuminate\Console\Command;
use Throwable;

/**
 * 一括操作の取消イベントの対象行idの補正と、利用者の設定値(タイムゾーン・テナントID)の補正を、1回のトランザクションでまとめて行う運用コマンド
 * (docs/changesets/20261124-user-projection-settings-at-replay/spec.md 論点3)。
 *
 * - 2つの補正は別々に順に実行すると成り立たないため、書き換えの前にライブから2つの対応表を作り、両方を書き換え、1回だけ作り直し、検証する。
 * - 既定は試し実行(2つの補正の試し実行の結果をまとめて出すだけで、何も書かない)。`--execute`で、バックアップ表を作ったうえで実行する。失敗したら全て戻る(バックアップ表は残る)。
 * - 片方だけが必要な環境でも使える(対象の無い補正は何もしない)。
 * - 記録・心拍・メンテナンス・排他は`read-models:rebuild`と同じ仕組みを使う。
 */
#[AdminExecutable(label: '再生の決定性の補正(一括操作・利用者の設定値)')]
class FixRebuildDeterminismCommand extends Command
{
    protected $signature = 'events:fix-rebuild-determinism
        {--execute : 書き換えを実行する(省略時は試し実行で、何も変更しない)}';

    protected $description = '一括操作の取消イベントの対象行idの補正と、利用者の設定値(タイムゾーン・テナントID)の補正を、1つのトランザクションでまとめて行い、ReadModelを作り直して検証する。'
        .'既定は試し実行(件数と対応表を出すだけ)。--execute で実行する。実行中は全利用者がメンテナンス中(全API 503)になる。'
        .'日次バッチの時刻(1:00〜2:20)を避けて実行すること。';

    public function handle(RebuildDeterminismMigrator $migrator, ReadModelRebuildRunService $runService): int
    {
        $execute = (bool) $this->option('execute');
        $dryRun = ! $execute;
        $startedAt = microtime(true);

        $run = CurrentAdminCommandRun::get();
        $direct = $run === null;
        if ($direct) {
            $run = $runService->startDirectRun($dryRun, ReadModelRebuildRunService::FIX_DETERMINISM_COMMAND_NAME);
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
            $backups = $migrator->backupTables();
            $message = '補正を実行できませんでした: '.$e->getMessage()
                .($backups !== [] ? ' ロールバックしたため、stored_events・ReadModelは変わっていません(バックアップ表 '.implode(', ', $backups).' は残っています)。' : ' 何も変更していません。');
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
        $this->line('--- 一括操作の取消イベントの対象行id ---');
        $bulk = $summary['bulk'];
        if ($bulk['unmapped'] !== []) {
            $this->warn('対応が付かないid: '.count($bulk['unmapped']).' 件。--execute はこの行がある間エラーで止まります。人が確認してください。');
            foreach ($bulk['unmapped'] as $row) {
                $this->line("  - stored_events.id={$row['event_id']} 操作={$row['operation_id']} id={$row['old_id']}");
            }
        }
        if ($bulk['status'] === 'nothing') {
            $this->info('対象なし(旧いid体系の取消イベントが無い)。');
        } else {
            $this->info("取消イベント {$bulk['event_count']} 件・id {$bulk['id_count']} 個(対応表 {$bulk['mapping_count']} 件)");
            $this->table(['操作ID', '旧id', '新id'], array_map(
                fn (array $row) => [$row['operation_id'], $row['old_id'], $row['new_id']],
                $bulk['mapping'],
            ));
            if ($bulk['mapping_count'] > count($bulk['mapping'])) {
                $this->line('(対応表は先頭 '.count($bulk['mapping']).' 件のみ表示)');
            }
        }

        $this->line('--- 利用者の設定値(タイムゾーン・テナントID) ---');
        FixUserSettingsAtReplayCommand::printSummary($this, $summary['user']);

        if ($summary['status'] === 'nothing') {
            $this->info('何も変更していません。');

            return;
        }
        $this->info($summary['dry_run'] ? '試し実行(データは変更していません。--execute で2つの補正を実行します)' : '2つの補正を書き換え、1回だけ作り直し、検証に成功しました。');
        foreach ($summary['backup_tables'] as $backup) {
            $this->line("バックアップ表: {$backup}");
        }
        if ($summary['dry_run']) {
            return;
        }

        $this->info('ReadModelの作り直し: 変更のあったテーブル'.$summary['changed_tables'].'件('.CalendarBulkOperationTargetIdMigrator::TARGET_TABLE.' だけが変更ありで合格)');
        $this->line('それ以外で変更のあったテーブル: '.($summary['other_changed_tables'] === [] ? 'なし' : implode(', ', $summary['other_changed_tables'])));
        $rows = $summary['tables'];
        usort($rows, fn (array $a, array $b) => [$b['changed'], $a['table']] <=> [$a['changed'], $b['table']]);
        $this->table(
            ['テーブル', '前', '後', '変更'],
            array_map(fn (array $row) => [$row['table'], $row['before'], $row['after'], $row['changed'] ? '変更あり' : ''], $rows),
        );
    }
}
