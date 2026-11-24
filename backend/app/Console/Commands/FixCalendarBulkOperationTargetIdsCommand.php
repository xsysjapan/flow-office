<?php

namespace App\Console\Commands;

use App\Console\Attributes\AdminExecutable;
use App\Console\CurrentAdminCommandRun;
use App\Domain\EventSourcing\Rebuild\CalendarBulkOperationTargetIdMigrator;
use App\Domain\EventSourcing\Rebuild\ReadModelRebuilder;
use App\Services\EventSourcing\ReadModelRebuildRunService;
use Illuminate\Console\Command;
use Throwable;

/**
 * 過去の取消イベント(calendar_bulk_operation.reverted)の対象行idを、再生しても変わらない新しいidへ書き換え、ReadModelを作り直す運用コマンド
 * (docs/changesets/20261123-rebuild-drift-fixes/spec.md 論点2・3)。
 *
 * - 既定は試し実行(書き換える件数・対応表・対応が付かないidを出すだけで、何も書かない)。`--execute`で、バックアップ表を作ったうえで
 *   1つのトランザクションで「書き換え → 補正ログ → ReadModelの作り直し → 検証」を行う。失敗したら全て戻る(バックアップ表は残る)。
 * - 対応が付かないidが1つでもあると、`--execute`は何も変えずに止まる。
 * - 記録・心拍・メンテナンス・排他は`read-models:rebuild`と同じ仕組みを使う。
 * - 本番はメンテナンス窓の中でSSHから実行する(docs/27-release-runbook.md)。
 */
#[AdminExecutable(label: '一括操作の対象行idの補正')]
class FixCalendarBulkOperationTargetIdsCommand extends Command
{
    protected $signature = 'events:fix-calendar-bulk-operation-target-ids
        {--execute : 書き換えを実行する(省略時は試し実行で、何も変更しない)}';

    protected $description = '過去の一括操作の取消イベントの対象行idを新しいid体系へ書き換え、ReadModelを作り直して検証する(1つのトランザクション)。'
        .'既定は試し実行(件数と対応表を出すだけ)。--execute で実行する。実行中は全利用者がメンテナンス中(全API 503)になる。'
        .'日次バッチの時刻(1:00〜2:20)を避けて実行すること。';

    public function handle(CalendarBulkOperationTargetIdMigrator $migrator, ReadModelRebuildRunService $runService): int
    {
        $execute = (bool) $this->option('execute');
        $dryRun = ! $execute;
        $startedAt = microtime(true);

        $run = CurrentAdminCommandRun::get();
        $direct = $run === null;
        if ($direct) {
            $run = $runService->startDirectRun($dryRun, ReadModelRebuildRunService::FIX_BULK_COMMAND_NAME);
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
        if ($summary['unmapped'] !== []) {
            $this->warn('対応が付かないid: '.count($summary['unmapped']).' 件。--execute はこの行がある間エラーで止まります。人が確認してください。');
            foreach ($summary['unmapped'] as $row) {
                $this->line("  - stored_events.id={$row['event_id']} 操作={$row['operation_id']} id={$row['old_id']}");
            }
        }
        if ($summary['status'] === 'nothing') {
            $this->info('対象なし(旧いid体系の取消イベントが無い)。何も変更していません。');

            return;
        }

        $this->info(($summary['dry_run'] ? '試し実行(データは変更していません。--execute で実行します)' : '書き換えました')
            .": 取消イベント {$summary['event_count']} 件・id {$summary['id_count']} 個(対応表 {$summary['mapping_count']} 件)");
        $this->table(['操作ID', '旧id', '新id'], array_map(
            fn (array $row) => [$row['operation_id'], $row['old_id'], $row['new_id']],
            $summary['mapping'],
        ));
        if ($summary['mapping_count'] > count($summary['mapping'])) {
            $this->line('(対応表は先頭 '.count($summary['mapping']).' 件のみ表示)');
        }
        if ($summary['backup_table'] !== null) {
            $this->line("バックアップ表: {$summary['backup_table']}");
        }
        if ($summary['dry_run']) {
            return;
        }

        $this->info('補正ログ(stored_event_corrections)に補正キー '.CalendarBulkOperationTargetIdMigrator::CORRECTION_KEY.' で記録しました。検証に成功しました。');
        $this->info('ReadModelの作り直し: 変更のあったテーブル'.$summary['changed_tables'].'件(対象行のid自体は変わるため、'.CalendarBulkOperationTargetIdMigrator::TARGET_TABLE.' は変更ありになります)');
        $this->line('それ以外で変更のあったテーブル: '.($summary['other_changed_tables'] === [] ? 'なし' : implode(', ', $summary['other_changed_tables'])));
        $rows = $summary['tables'];
        usort($rows, fn (array $a, array $b) => [$b['changed'], $a['table']] <=> [$a['changed'], $b['table']]);
        $this->table(
            ['テーブル', '前', '後', '変更'],
            array_map(fn (array $row) => [$row['table'], $row['before'], $row['after'], $row['changed'] ? '変更あり' : ''], $rows),
        );
    }
}
