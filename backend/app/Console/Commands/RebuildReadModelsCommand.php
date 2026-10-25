<?php

namespace App\Console\Commands;

use App\Console\Attributes\AdminExecutable;
use App\Console\CurrentAdminCommandRun;
use App\Domain\EventSourcing\Rebuild\ReadModelRebuilder;
use App\Services\EventSourcing\ReadModelRebuildRunService;
use Illuminate\Console\Command;
use Throwable;

/**
 * ReadModelの作り直し(全テーブルを空にして全Projectorをreplay)の運用コマンド
 * (docs/changesets/20261025-read-model-rebuild-admin-command/spec.md 論点2)。
 *
 * - 運用コマンドの画面から依頼されたときは、実行役(`admin-commands:run-pending`)が渡した記録(`CurrentAdminCommandRun`)を使う。
 *   記録の`succeeded`/`failed`・`exit_code`・`output`は実行役が書き、このコマンドは`summary`と`error_message`を書く。
 * - CLIから直接実行したときは、自分で記録(依頼者なし、`running`)を作り、終了時に更新する(メンテナンスに入るため)。
 *   作り直しが実行中、または有給付与予定の判定が`queued`/`running`なら、始めずに終了コード1。
 * - 心拍を書き、設定の秒数(既定60秒)待ってから`ReadModelRebuilder::rebuild()`を呼ぶ。
 */
#[AdminExecutable(label: 'データの作り直し(ReadModel)')]
class RebuildReadModelsCommand extends Command
{
    protected $signature = 'read-models:rebuild
        {--dry-run : 試し実行(最後にロールバックし、データを変更しない)}';

    protected $description = '全ReadModelのテーブルを空にして、イベントから作り直す。実行中は全利用者がメンテナンス中(全API 503)になる。'
        .'日次バッチの時刻(1:00〜2:20)を避けて実行すること。「試し実行」は最後にロールバックし、変更のあるテーブルを出力に表示するだけでデータは変えない。';

    public function handle(ReadModelRebuilder $rebuilder, ReadModelRebuildRunService $runService): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $run = CurrentAdminCommandRun::get();
        $direct = $run === null;
        if ($direct) {
            $run = $runService->startDirectRun($dryRun);
            if ($run === null) {
                $this->error($runService->isRunning()
                    ? ReadModelRebuildRunService::REBUILD_ACTIVE_MESSAGE
                    : ReadModelRebuildRunService::ASSESSMENT_ACTIVE_MESSAGE);

                return self::FAILURE;
            }
        }

        try {
            ReadModelRebuilder::heartbeat();
            $wait = (int) config('read_model_rebuild.claim_wait_seconds', 60);
            if ($wait > 0) {
                sleep($wait);
            }

            $summary = $rebuilder->rebuild($dryRun);
        } catch (Throwable $e) {
            report($e);
            $this->error('作り直しを実行できませんでした: '.$e->getMessage());
            $run->update(['error_message' => mb_substr($e->getMessage(), 0, 2000)]);
            ReadModelRebuilder::clearHeartbeat();
            if ($direct) {
                $runService->markFailed($run, $e->getMessage());
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

    /** @param  array{dry_run: bool, tables: list<array{table: string, before: int, after: int, changed: bool}>, changed_tables: int}  $summary */
    private function printResult(array $summary): void
    {
        $this->info('作り直しました('.($summary['dry_run'] ? '試し実行: データは変更していません' : '本実行').'): 変更のあったテーブル'.$summary['changed_tables'].'件');

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
