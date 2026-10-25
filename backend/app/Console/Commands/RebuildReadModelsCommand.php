<?php

namespace App\Console\Commands;

use App\Domain\EventSourcing\Rebuild\ReadModelRebuilder;
use App\Services\EventSourcing\ReadModelRebuildRunService;
use Illuminate\Console\Command;
use Throwable;

/**
 * 管理画面から依頼された、ReadModelの作り直し(全テーブルを空にして全Projectorをreplay)を実行する
 * (docs/changesets/20261025-admin-read-model-rebuild/spec.md)。
 *
 * - `--pending`: 待機中(`queued`)の記録を1件取り出して`running`にし(コミット)、設定の秒数(既定60秒)待ってから実行する。
 *   有給付与予定の判定が`queued`/`running`の間は取り出さない。
 * - 記録の`succeeded`/`failed`・summary・`error_message`の更新と、終了の監査イベントの記録は`ReadModelRebuildRunService`が行う。
 * - 運用コマンド画面からは実行させない(`#[AdminExecutable]`を付けない。全利用者を止めるため経路を1つにする)。
 */
class RebuildReadModelsCommand extends Command
{
    protected $signature = 'read-models:rebuild
        {--pending : 待機中の記録を1件取り出して実行する}';

    protected $description = '管理画面から依頼された、ReadModelの作り直し(全テーブルを空にして全Projectorをreplay)を実行する';

    public function handle(ReadModelRebuilder $rebuilder, ReadModelRebuildRunService $runService): int
    {
        if (! $this->option('pending')) {
            $this->error('--pending を指定してください(実行は管理画面からの依頼のみです)。');

            return self::FAILURE;
        }

        $run = $runService->claimPendingRun();
        if ($run === null) {
            $this->info('実行できる待機中の記録がありません(または実行中・判定の実行中です)。');

            return self::SUCCESS;
        }

        try {
            $wait = (int) config('read_model_rebuild.claim_wait_seconds', 60);
            if ($wait > 0) {
                sleep($wait);
            }

            $summary = $rebuilder->rebuild((bool) ($run->parameters['dry_run'] ?? false), (string) $run->requested_by_user_id);
        } catch (Throwable $e) {
            report($e);
            $this->error('作り直しを実行できませんでした: '.$e->getMessage());
            $runService->markFailed($run, $e->getMessage());

            return self::FAILURE;
        }

        $runService->markSucceeded($run, $summary);
        $this->info("作り直しました(".($summary['dry_run'] ? '試し実行' : '本実行')."): 変更のあったテーブル{$summary['changed_tables']}件");

        return self::SUCCESS;
    }
}
