<?php

namespace App\Console\Commands;

use App\Console\Attributes\AdminExecutable;
use App\Console\CurrentAdminCommandRun;
use App\Domain\EventSourcing\Rebuild\BooleanPropertyMigrator;
use App\Domain\EventSourcing\Rebuild\LegacyUserSettingsDetector;
use App\Domain\EventSourcing\Rebuild\ReadModelRebuilder;
use App\Services\EventSourcing\ReadModelRebuildRunService;
use Illuminate\Console\Command;
use Throwable;

/**
 * 旧項目名で記録された stored_events の真偽値の項目を新しい項目名へ書き換え、ReadModelを作り直す運用コマンド
 * (docs/changesets/20261123-boolean-column-naming/spec.md 論点5・7)。変換表は`BooleanPropertyConverter::RULES`。
 *
 * - 既定は試し実行(対象の event_class ごとの件数・補う件数・旧→新の例を出すだけで、何も書かない)。`--execute`で、バックアップ表を作ったうえで
 *   1つのトランザクションで「書き換え → ReadModelの作り直し → 検証」を行う。失敗したら全て戻る(バックアップ表は残る)。
 * - 記録・心拍・メンテナンス・排他は`read-models:rebuild`と同じ仕組みを使う(実行役から呼ばれたときは`CurrentAdminCommandRun`、CLIから直接のときは`startDirectRun`)。
 * - 本番はメンテナンス窓の中でSSHから実行する(docs/27-release-runbook.md)。次のリリースで削除する。
 */
#[AdminExecutable(label: '真偽値のイベント項目名の書き換え')]
class RenameBooleanEventPropertiesCommand extends Command
{
    protected $signature = 'events:rename-boolean-properties
        {--execute : 書き換えを実行する(省略時は試し実行で、何も変更しない)}';

    protected $description = '旧項目名で記録された stored_events の真偽値の項目を新しい項目名へ書き換え、ReadModelを作り直して検証する(1つのトランザクション)。'
        .'既定は試し実行(件数と旧→新の例を出すだけ)。--execute で実行する。実行中は全利用者がメンテナンス中(全API 503)になる。'
        .'日次バッチの時刻(1:00〜2:20)を避けて実行すること。';

    public function handle(BooleanPropertyMigrator $migrator, ReadModelRebuildRunService $runService, LegacyUserSettingsDetector $userSettingsDetector): int
    {
        // 未補正の利用者のイベントが残っている間は、このコマンドの作り直しが例外になるため、試し実行も含めて拒否し、まとめの補正を案内する。
        $userSettingsCount = $userSettingsDetector->count();
        if ($userSettingsCount > 0) {
            $message = LegacyUserSettingsDetector::messageForFixCommand($userSettingsCount);
            $this->error($message);
            CurrentAdminCommandRun::get()?->update(['error_message' => $message]);

            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');
        $dryRun = ! $execute;
        $startedAt = microtime(true);

        $run = CurrentAdminCommandRun::get();
        $direct = $run === null;
        if ($direct) {
            $run = $runService->startDirectRun($dryRun, ReadModelRebuildRunService::RENAME_COMMAND_NAME);
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
            $message = '書き換えを実行できませんでした: '.$e->getMessage()
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
        if ($summary['unfillable'] !== []) {
            $this->warn('補えない行(旧キーも新キーも無く、補う値も無い必須項目): '.count($summary['unfillable']).' 件。--execute はこの行がある間エラーで止まります。人が確認してください。');
            foreach ($summary['unfillable'] as $row) {
                $this->line("  - id={$row['id']} {$row['event_class']}: {$row['reason']}");
            }
        }
        if ($summary['status'] === 'nothing') {
            $this->info('対象なし(旧項目名の行が無い)。何も変更していません。');

            return;
        }

        $this->info(($summary['dry_run'] ? '試し実行(データは変更していません。--execute で実行します)' : '書き換えました')
            .": 対象 {$summary['target_count']} 件(うち旧キーが無く既定値を補う行 {$summary['filled_count']} 件)");
        $this->table(['event_class', '対象', '補う', '例(id)', '旧 → 新'], array_map(
            fn (string $class, array $event) => [
                $class, $event['target'], $event['filled'],
                $event['example']['id'].($event['filled_example'] !== null && $event['filled_example']['id'] !== $event['example']['id'] ? ' / 補完 '.$event['filled_example']['id'] : ''),
                implode(' / ', $event['example']['changes'])
                    .($event['filled_example'] !== null && $event['filled_example']['id'] !== $event['example']['id'] ? ' / 補完の例: '.implode(' / ', $event['filled_example']['changes']) : ''),
            ],
            array_keys($summary['events']),
            array_values($summary['events']),
        ));
        if ($summary['backup_table'] !== null) {
            $this->line("バックアップ表: {$summary['backup_table']}");
        }
        if ($summary['dry_run']) {
            return;
        }

        $this->info('ReadModelの作り直し: 変更のあったテーブル'.$summary['changed_tables'].'件(0件が合格)');
        $rows = $summary['tables'];
        usort($rows, fn (array $a, array $b) => [$b['changed'], $a['table']] <=> [$a['changed'], $b['table']]);
        $this->table(
            ['テーブル', '前', '後', '変更'],
            array_map(fn (array $row) => [$row['table'], $row['before'], $row['after'], $row['changed'] ? '変更あり' : ''], $rows),
        );
    }
}
