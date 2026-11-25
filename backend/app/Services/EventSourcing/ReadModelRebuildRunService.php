<?php

namespace App\Services\EventSourcing;

use App\Domain\EventSourcing\Rebuild\ReadModelRebuilder;
use App\Models\AdminCommandRun;
use App\Services\PaidLeave\ScheduleAssessmentRunService;
use Illuminate\Contracts\Cache\LockTimeoutException;
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

    public const RENAME_COMMAND_NAME = 'events:rename-boolean-properties';

    public const FIX_BULK_COMMAND_NAME = 'events:fix-calendar-bulk-operation-target-ids';

    public const FIX_USER_SETTINGS_COMMAND_NAME = 'events:fix-user-settings-at-replay';

    public const FIX_DETERMINISM_COMMAND_NAME = 'events:fix-rebuild-determinism';

    /** メンテナンス(全API 503)を要するコマンド名。いずれかが`running`の間はメンテナンスで、互いに同時には開始しない。 */
    public const MAINTENANCE_COMMANDS = [self::COMMAND_NAME, self::RENAME_COMMAND_NAME, self::FIX_BULK_COMMAND_NAME, self::FIX_USER_SETTINGS_COMMAND_NAME, self::FIX_DETERMINISM_COMMAND_NAME];

    public const LOCK_NAME = 'read-model-rebuild-run';

    public const REBUILD_ACTIVE_MESSAGE = '作り直しを実行中です。';

    public const ASSESSMENT_ACTIVE_MESSAGE = '有給付与予定の判定の実行中です。終わってから実行してください。';

    /**
     * CLIから直接実行するときの記録(`running`、依頼者なし、心拍あり)を作る。メンテナンスを要するコマンドが実行中(`isRunning()`)、または有給付与予定の判定が`queued`/`running`ならnull。
     * `$commandName`は記録するコマンド名(`MAINTENANCE_COMMANDS`のいずれか)。
     */
    public function startDirectRun(bool $dryRun, string $commandName = self::COMMAND_NAME): ?AdminCommandRun
    {
        return Cache::lock(self::LOCK_NAME, 30)->block(10, function () use ($dryRun, $commandName): ?AdminCommandRun {
            if ($this->isRunning() || $this->isAssessmentActive()) {
                return null;
            }

            $run = AdminCommandRun::query()->create([
                'command_name' => $commandName,
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
        app(CorrectionPendingService::class)->forget();
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
        app(CorrectionPendingService::class)->forget();
    }

    /** 有給付与予定の判定が`queued`/`running`か(作り直しを`running`にするとメンテナンスで判定が止まるため、その間は始めない)。 */
    public function isAssessmentActive(): bool
    {
        return app(ScheduleAssessmentRunService::class)->hasActiveRun();
    }

    /**
     * 実行役から呼ばれたコマンドが、自分の記録以外のメンテナンスを要するコマンドが実行中かを`LOCK_NAME`のロックの中で確かめる
     * (直接実行の`startDirectRun`と同じ排他。ロックが取れなければ実行中とみなす)。
     */
    public function isOtherMaintenanceRunning(AdminCommandRun $own): bool
    {
        try {
            return Cache::lock(self::LOCK_NAME, 30)->block(10, fn (): bool => $this->isRunning($own->id));
        } catch (LockTimeoutException) {
            return true;
        }
    }

    /**
     * メンテナンス中か(`MAINTENANCE_COMMANDS`のいずれかの`running`の記録があり、心拍が10分以内)。読むだけで、書かない。
     * 全APIのミドルウェアとスケジューラーから呼ばれるので、テーブルが無い環境(migrate前)でも壊さずfalseを返す。
     */
    public function isRunning(?string $exceptRunId = null): bool
    {
        try {
            $query = AdminCommandRun::query()
                ->whereIn('command_name', self::MAINTENANCE_COMMANDS)
                ->where('status', 'running');
            if ($exceptRunId !== null) {
                $query->where('id', '!=', $exceptRunId);
            }
            $runs = $query->get(['id', 'started_at']);
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
