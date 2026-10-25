<?php

namespace App\Services\EventSourcing;

use App\Domain\EventSourcing\Rebuild\ReadModelRebuilder;
use App\Models\AdminCommandRun;
use App\Services\PaidLeave\ScheduleAssessmentRunService;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * ReadModelの作り直し(`read-models:rebuild`)の実行の記録(`admin_command_runs`)のうち、メンテナンス中の判定・
 * 有給付与予定の判定との調整・CLIから直接実行したときの記録の作成と終了だけを持つ
 * (docs/changesets/20261025-read-model-rebuild-admin-command/spec.md 論点2・3)。
 * 運用コマンドの依頼・取り出し・実行は`AdminCommandRunner`と`admin-commands:run-pending`が行う。
 * 実行の記録は運用の記録で、業務の状態ではない(イベントにしない)。
 */
class ReadModelRebuildRunService
{
    public const COMMAND_NAME = 'read-models:rebuild';

    public const LOCK_NAME = 'read-model-rebuild-run';

    public const REBUILD_ACTIVE_MESSAGE = '作り直しを実行中です。';

    public const ASSESSMENT_ACTIVE_MESSAGE = '有給付与予定の判定の実行中です。終わってから実行してください。';

    /**
     * CLIから直接実行するときの記録(`running`、依頼者なし、心拍あり)を作る。作り直しが実行中(`isRunning()`)、または有給付与予定の判定が`queued`/`running`ならnull。
     */
    public function startDirectRun(bool $dryRun): ?AdminCommandRun
    {
        return Cache::lock(self::LOCK_NAME, 30)->block(10, function () use ($dryRun): ?AdminCommandRun {
            if ($this->isRunning() || $this->isAssessmentActive()) {
                return null;
            }

            $run = AdminCommandRun::query()->create([
                'command_name' => self::COMMAND_NAME,
                'parameters' => ['dry-run' => $dryRun],
                'status' => 'running',
                'started_at' => now(),
            ]);
            ReadModelRebuilder::heartbeat();

            return $run;
        });
    }

    /**
     * 成功にする(CLIから直接実行した記録用)。心拍を消し、メンテナンスが解ける。
     *
     * @param  array<string, mixed>  $summary
     */
    public function markSucceeded(AdminCommandRun $run, array $summary): void
    {
        $run->update([
            'status' => 'succeeded',
            'finished_at' => now(),
            'exit_code' => 0,
            'summary' => $summary,
            'error_message' => null,
        ]);
        ReadModelRebuilder::clearHeartbeat();
    }

    public function markFailed(AdminCommandRun $run, string $message): void
    {
        $run->update([
            'status' => 'failed',
            'finished_at' => now(),
            'exit_code' => 1,
            'error_message' => mb_substr($message, 0, 2000),
        ]);
        ReadModelRebuilder::clearHeartbeat();
    }

    /** 有給付与予定の判定が`queued`/`running`か(作り直しを`running`にするとメンテナンスで判定が止まるため、その間は始めない)。 */
    public function isAssessmentActive(): bool
    {
        return app(ScheduleAssessmentRunService::class)->hasActiveRun();
    }

    /**
     * メンテナンス中か(作り直しの`running`の記録があり、心拍が10分以内)。読むだけで、書かない。
     * 全APIのミドルウェアとスケジューラーから呼ばれるので、テーブルが無い環境(migrate前)でも壊さずfalseを返す。
     */
    public function isRunning(): bool
    {
        try {
            $runs = AdminCommandRun::query()
                ->where('command_name', self::COMMAND_NAME)
                ->where('status', 'running')
                ->get(['id', 'started_at']);
            foreach ($runs as $run) {
                if ($this->isRunningAlive($run)) {
                    return true;
                }
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }

    /** `running`の記録が生きているか(心拍、無ければ`started_at`が、設定の分数以内)。 */
    private function isRunningAlive(AdminCommandRun $run): bool
    {
        $last = max(ReadModelRebuilder::lastHeartbeat() ?? 0, $run->started_at?->getTimestamp() ?? 0);

        return $last >= now()->subMinutes((int) config('read_model_rebuild.heartbeat_stale_minutes', 10))->getTimestamp();
    }
}
