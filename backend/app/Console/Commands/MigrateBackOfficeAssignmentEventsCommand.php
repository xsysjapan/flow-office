<?php

namespace App\Console\Commands;

use App\Console\Attributes\AdminExecutable;
use App\Console\CurrentAdminCommandRun;
use App\Domain\EventSourcing\Rebuild\BackOfficeAssignmentMigrator;
use App\Domain\EventSourcing\Rebuild\ReadModelRebuilder;
use App\Services\EventSourcing\ReadModelRebuildRunService;
use Illuminate\Console\Command;
use Throwable;

/**
 * 旧形式のバックオフィス割当イベント(backoffice_task.assigned)の移行(stored_events の作り直し)とReadModelの作り直しを、
 * 1つのDBトランザクションで行う運用コマンド(docs/changesets/20261122-backoffice-assignment-migration-admin/spec.md)。
 *
 * - 記録・心拍・メンテナンス・排他は`read-models:rebuild`(`RebuildReadModelsCommand`)と同じ仕組みを使う
 *   (実行役から呼ばれたときは`CurrentAdminCommandRun`、CLIから直接のときは`startDirectRun`)。
 * - 本実行は、トランザクションの前にバックアップ表(stored_events_before_rebuild_YYYYMMDDHHMMSS)を作る。
 *   失敗時はトランザクションごとロールバックされ、stored_events・ReadModelは変わらない(バックアップ表は残る)。
 */
#[AdminExecutable(label: 'バックオフィス割当イベントの移行')]
class MigrateBackOfficeAssignmentEventsCommand extends Command
{
    protected $signature = 'backoffice:migrate-assignment-events
        {--dry-run : 試し実行(最後にロールバックし、データを変更しない)}';

    protected $description = '旧形式のバックオフィス割当イベントを新しい構成へ移行し、ReadModelを作り直す(1つのトランザクション)。'
        .'実行中は全利用者がメンテナンス中(全API 503)になる。日次バッチの時刻(1:00〜2:20)を避けて実行すること。'
        .'先に「試し実行」で件数とReadModelの差分を確かめる(試し実行は最後にロールバックし、データは変えない)。';

    public function handle(BackOfficeAssignmentMigrator $migrator, ReadModelRebuildRunService $runService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $startedAt = microtime(true);

        $run = CurrentAdminCommandRun::get();
        $direct = $run === null;
        if ($direct) {
            $run = $runService->startDirectRun($dryRun, ReadModelRebuildRunService::MIGRATE_COMMAND_NAME);
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
            if ($wait > 0) {
                sleep($wait);
            }

            $summary = $migrator->migrate($dryRun, $startedAt);
        } catch (Throwable $e) {
            report($e);
            $backup = $migrator->backupTable();
            $message = '移行を実行できませんでした: '.$e->getMessage()
                .' ロールバックしたため、stored_events・ReadModelは変わっていません'
                .($backup !== null ? "(バックアップ表 {$backup} は残っています)" : '').'。';
            $this->error($message);
            $run->update(['error_message' => mb_substr($message, 0, 2000)]);
            ReadModelRebuilder::clearHeartbeat();
            if ($direct) {
                $runService->markFailed($run, $message);
            }

            return self::FAILURE;
        }

        // `summary`は、試し実行ではロールバックの後(トランザクションの外)で保存する。
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
        if ($summary['status'] === 'nothing') {
            $this->info('対象なし(旧形式の backoffice_task.assigned が無い)。何も変更していません。');

            return;
        }

        $this->info('移行しました('.($summary['dry_run'] ? '試し実行: データは変更していません' : '本実行').')');
        $this->line("変換した旧形式の assigned: {$summary['target_count']} 件 / 挿入した status_changed: {$summary['inserted_count']} 件 / 変換済みでそのまま写した assigned: {$summary['skipped_converted']} 件");
        $this->table(['直前の status', '件数'], array_map(
            fn (string $status, int $count) => [$status, $count],
            array_keys($summary['previous_status_counts']),
            array_values($summary['previous_status_counts']),
        ));
        foreach ($summary['terminal_aggregates'] as $status => $uuids) {
            $this->warn("直前が {$status} の assigned(旧挙動の再現として {$status}→in_review の status_changed を挿入): ".count($uuids).' 件');
            foreach ($uuids as $uuid) {
                $this->line("  - {$uuid}");
            }
        }
        $this->line("補正ログの付け替え: {$summary['corrections_relinked']} 件(対応が無く変更しなかった行: {$summary['corrections_unmapped']} 件)");
        if ($summary['backup_table'] !== null) {
            $this->line("バックアップ表: {$summary['backup_table']}");
        }

        $this->info('ReadModelの作り直し: 変更のあったテーブル'.$summary['changed_tables'].'件');
        $accountTables = ReadModelRebuilder::changedAccountTables($summary['tables']);
        if ($accountTables !== []) {
            $this->warn('警告: 利用者・権限のテーブルに変更があります(イベントを経ずに作られた行がある可能性があります): '.implode(', ', $accountTables));
        }
        $rows = $summary['tables'];
        usort($rows, fn (array $a, array $b) => [$b['changed'], $a['table']] <=> [$a['changed'], $b['table']]);
        $this->table(
            ['テーブル', '前', '後', '変更'],
            array_map(fn (array $row) => [$row['table'], $row['before'], $row['after'], $row['changed'] ? '変更あり' : ''], $rows),
        );
    }
}
