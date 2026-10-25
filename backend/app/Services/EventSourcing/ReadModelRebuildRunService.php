<?php

namespace App\Services\EventSourcing;

use App\Domain\EventSourcing\Rebuild\ReadModelRebuilder;
use App\Domain\ReadModelRebuild\Aggregates\ReadModelRebuildAuditAggregate;
use App\Models\AdminCommandRun;
use App\Models\User;
use App\Services\PaidLeave\ScheduleAssessmentRunService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * ReadModelの作り直し(`read-models:rebuild`)の実行の記録(`admin_command_runs`)の作成・取り出し・固まった記録の扱い・
 * メンテナンス中の判定(docs/changesets/20261025-admin-read-model-rebuild/spec.md)。
 * `ScheduleAssessmentRunService`と同じ作り(`Cache::lock`の中で`queued`/`running`を確かめて二重実行を防ぐ)。
 * 実行の記録は運用の記録で、業務の状態ではない(イベントにしない)。依頼と終了は監査イベントを`stored_events`へ記録する。
 */
class ReadModelRebuildRunService
{
    public const COMMAND_NAME = 'read-models:rebuild';

    public const LOCK_NAME = 'read-model-rebuild-run';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STALE_MESSAGE = '応答がないため失敗とみなしました';

    public const ACTIVE_MESSAGE = '作り直しを実行中・待機中です。終わってから実行してください。';

    /**
     * `queued`の記録を作り、依頼の監査イベントを同じトランザクションで記録する。`queued`/`running`の記録があればnull(409にする)。
     */
    public function createRun(User $requester, bool $dryRun): ?AdminCommandRun
    {
        return $this->withLock(function () use ($requester, $dryRun): ?AdminCommandRun {
            $this->failStaleRuns();

            if ($this->hasActiveRun()) {
                return null;
            }

            return DB::transaction(function () use ($requester, $dryRun): AdminCommandRun {
                $run = AdminCommandRun::query()->create([
                    'command_name' => self::COMMAND_NAME,
                    'parameters' => ['dry_run' => $dryRun],
                    'status' => self::STATUS_QUEUED,
                    'requested_by_user_id' => $requester->id,
                ]);

                ReadModelRebuildAuditAggregate::retrieve(ReadModelRebuildAuditAggregate::aggregateId())
                    ->recordRequested($run->id, $requester->id, $dryRun)
                    ->persist();

                return $run;
            });
        });
    }

    /**
     * 待っている記録を1件(古い順)取り出して`running`にし、心拍を書く。
     * `running`の記録がある、待っている記録が無い、または有給付与予定の判定が`queued`/`running`ならnull。
     */
    public function claimPendingRun(): ?AdminCommandRun
    {
        return $this->withLock(function (): ?AdminCommandRun {
            $this->failStaleRuns();

            if ($this->assessmentRunService()->hasActiveRun()) {
                return null;
            }

            if ($this->baseQuery()->where('status', self::STATUS_RUNNING)->exists()) {
                return null;
            }

            $run = $this->baseQuery()
                ->where('status', self::STATUS_QUEUED)
                ->orderBy('created_at')
                ->orderBy('id')
                ->first();

            if ($run === null) {
                return null;
            }

            $run->update(['status' => self::STATUS_RUNNING, 'started_at' => now()]);
            ReadModelRebuilder::heartbeat();

            return $run;
        });
    }

    /**
     * 成功にする。心拍を消し(メンテナンスが解ける)、終了の監査イベントを記録する。
     *
     * @param  array<string, mixed>  $summary
     */
    public function markSucceeded(AdminCommandRun $run, array $summary): void
    {
        $run->update([
            'status' => self::STATUS_SUCCEEDED,
            'finished_at' => now(),
            'exit_code' => 0,
            'summary' => $summary,
            'error_message' => null,
        ]);
        ReadModelRebuilder::clearHeartbeat();
        $this->recordFinished($run, self::STATUS_SUCCEEDED, (int) ($summary['changed_tables'] ?? 0));
    }

    public function markFailed(AdminCommandRun $run, string $message): void
    {
        $run->update([
            'status' => self::STATUS_FAILED,
            'finished_at' => now(),
            'exit_code' => 1,
            'error_message' => mb_substr($message, 0, 2000),
        ]);
        ReadModelRebuilder::clearHeartbeat();
        $this->recordFinished($run, self::STATUS_FAILED, 0);
    }

    /**
     * 固まった記録を失敗にする(`running`のまま心拍が古い、`queued`のまま作成から30分超(判定の実行を待っている間は除く))。
     *
     * @return int 失敗にした件数
     */
    public function failStaleRuns(): int
    {
        $failed = 0;

        foreach ($this->baseQuery()->where('status', self::STATUS_RUNNING)->get() as $run) {
            if ($this->isRunningAlive($run)) {
                continue;
            }
            $this->failAsStale($run);
            $failed++;
        }

        if (! $this->assessmentRunService()->hasActiveRun()) {
            $stale = $this->baseQuery()
                ->where('status', self::STATUS_QUEUED)
                ->where('created_at', '<', now()->subMinutes((int) config('read_model_rebuild.queued_timeout_minutes', 30)))
                ->get();
            foreach ($stale as $run) {
                $this->failAsStale($run);
                $failed++;
            }
        }

        return $failed;
    }

    /**
     * メンテナンス中か(作り直しの`running`の記録があり、心拍が10分以内)。読むだけで、書かない。
     * 全APIのミドルウェアとスケジューラーから呼ばれるので、テーブルが無い環境(migrate前)でも壊さずfalseを返す。
     */
    public function isRunning(): bool
    {
        try {
            foreach ($this->baseQuery()->where('status', self::STATUS_RUNNING)->get(['id', 'started_at']) as $run) {
                if ($this->isRunningAlive($run)) {
                    return true;
                }
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }

    public function hasActiveRun(): bool
    {
        return $this->baseQuery()->whereIn('status', [self::STATUS_QUEUED, self::STATUS_RUNNING])->exists();
    }

    /**
     * 直近の記録(新しい順)。
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, AdminCommandRun>
     */
    public function recentRuns(int $limit = 20)
    {
        $this->failStaleRuns();

        return $this->baseQuery()
            ->with('requestedByUser:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function findRun(string $id): ?AdminCommandRun
    {
        $this->failStaleRuns();

        return $this->baseQuery()->with('requestedByUser:id,name')->whereKey($id)->first();
    }

    /** `running`の記録が生きているか(心拍、無ければ`started_at`が、設定の分数以内)。 */
    private function isRunningAlive(AdminCommandRun $run): bool
    {
        $last = max(ReadModelRebuilder::lastHeartbeat() ?? 0, $run->started_at?->getTimestamp() ?? 0);

        return $last >= now()->subMinutes((int) config('read_model_rebuild.heartbeat_stale_minutes', 10))->getTimestamp();
    }

    private function failAsStale(AdminCommandRun $run): void
    {
        $run->update([
            'status' => self::STATUS_FAILED,
            'finished_at' => now(),
            'error_message' => self::STALE_MESSAGE,
        ]);
        $this->recordFinished($run, self::STATUS_FAILED, 0);
    }

    private function recordFinished(AdminCommandRun $run, string $result, int $changedTables): void
    {
        ReadModelRebuildAuditAggregate::retrieve(ReadModelRebuildAuditAggregate::aggregateId())
            ->recordFinished($run->id, $result, (bool) ($run->parameters['dry_run'] ?? false), $changedTables)
            ->persist();
    }

    private function assessmentRunService(): ScheduleAssessmentRunService
    {
        return app(ScheduleAssessmentRunService::class);
    }

    /**
     * @return Builder<AdminCommandRun>
     */
    private function baseQuery()
    {
        return AdminCommandRun::query()->where('command_name', self::COMMAND_NAME);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withLock(callable $callback): mixed
    {
        return Cache::lock(self::LOCK_NAME, 30)->block(10, $callback);
    }
}
