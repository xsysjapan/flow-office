<?php

namespace App\Domain\Attendance\Projectors;

use App\Domain\CompensatoryLeaveAccount\Events\CompensatoryLeaveAccountGrantCancelled;
use App\Domain\CompensatoryLeaveAccount\Events\CompensatoryLeaveAccountGrantConfirmed;
use App\Domain\CompensatoryLeaveAccount\Events\CompensatoryLeaveAccountGrantManuallyGranted;
use App\Domain\CompensatoryLeaveAccount\Events\CompensatoryLeaveAccountGrantRemoved;
use App\Domain\CompensatoryLeaveAccount\Events\CompensatoryLeaveAccountGrantSynced;
use App\Domain\CompensatoryLeaveAccount\Events\CompensatoryLeaveAccountMigrated;
use App\Domain\CompensatoryLeaveAccount\Events\CompensatoryLeaveAccountUsageCancelled;
use App\Domain\CompensatoryLeaveAccount\Events\CompensatoryLeaveAccountUsageConfirmed;
use App\Models\CompensatoryGrantDayView;
use App\Models\CompensatoryGrantDayViewAllocation;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * 代休口座のイベントから、勤怠側の代休付与のビュー(compensatory_grant_day_views)を作る(仕様確定事項I。
 * 月次APIの代休の警告が、代休の付与テーブルを直接読まずにこのビューを読むために置く)。
 *
 * 利用者×日付で付与の日数・時間・確定状況・充当量を持つ。充当量は充当の表(compensatory_grant_day_view_allocations)の
 * 合計から毎回計算し直す(加減算しない。リビルドしても同じ結果)。行の存在を前提とする更新は、行が無ければ何もしない。
 */
class CompensatoryGrantDayViewProjector extends Projector
{
    public function onCompensatoryLeaveAccountGrantSynced(CompensatoryLeaveAccountGrantSynced $event): void
    {
        $this->upsert($event->grantId, $event->userId, $event->sourceWorkDate, $event->grantedDays, $event->grantedMinutes, 'draft');
    }

    public function onCompensatoryLeaveAccountGrantManuallyGranted(CompensatoryLeaveAccountGrantManuallyGranted $event): void
    {
        $this->upsert($event->grantId, $event->userId, $event->sourceWorkDate, $event->grantedDays, $event->grantedMinutes, 'confirmed');
    }

    public function onCompensatoryLeaveAccountGrantConfirmed(CompensatoryLeaveAccountGrantConfirmed $event): void
    {
        CompensatoryGrantDayView::query()->whereKey($event->grantId)->update(['status' => 'confirmed']);
    }

    public function onCompensatoryLeaveAccountGrantCancelled(CompensatoryLeaveAccountGrantCancelled $event): void
    {
        CompensatoryGrantDayView::query()->whereKey($event->grantId)->update(['status' => 'cancelled']);
    }

    public function onCompensatoryLeaveAccountGrantRemoved(CompensatoryLeaveAccountGrantRemoved $event): void
    {
        CompensatoryGrantDayView::query()->whereKey($event->grantId)->delete();
    }

    /**
     * @param  CompensatoryLeaveAccountUsageConfirmed  $event
     */
    public function onCompensatoryLeaveAccountUsageConfirmed(CompensatoryLeaveAccountUsageConfirmed $event): void
    {
        $this->replaceAllocations($event->usageId, $event->allocations);
    }

    public function onCompensatoryLeaveAccountUsageCancelled(CompensatoryLeaveAccountUsageCancelled $event): void
    {
        $grantIds = CompensatoryGrantDayViewAllocation::query()
            ->where('usage_id', $event->usageId)
            ->pluck('grant_id')
            ->map(fn ($grantId) => (string) $grantId)
            ->all();

        CompensatoryGrantDayViewAllocation::query()->where('usage_id', $event->usageId)->delete();

        $this->recalculateGrants($grantIds);
    }

    /**
     * 移行(本変更前の付与・消化記録の状態)。付与の行と充当の表を、口座の移行イベントの内容で作る。
     */
    public function onCompensatoryLeaveAccountMigrated(CompensatoryLeaveAccountMigrated $event): void
    {
        foreach ($event->grants as $grant) {
            $this->upsert(
                (string) $grant['grantId'],
                $event->userId,
                $grant['sourceWorkDate'],
                (float) $grant['grantedDays'],
                $grant['grantedMinutes'],
                $grant['status'],
            );
        }

        foreach ($event->usages as $usage) {
            $this->replaceAllocations($usage['usageId'], $usage['allocations']);
        }
    }

    private function upsert(string $grantId, string $userId, string $workDate, float $grantedDays, ?int $grantedMinutes, string $status): void
    {
        CompensatoryGrantDayView::query()->updateOrCreate(
            ['grant_id' => $grantId],
            [
                'user_id' => $userId,
                'work_date' => $workDate,
                'granted_days' => $grantedDays,
                'granted_minutes' => $grantedMinutes,
                'status' => $status,
                'used_days' => 0,
                'used_minutes' => $grantedMinutes !== null ? 0 : null,
            ],
        );

        $this->recalculateGrant($grantId);
    }

    /**
     * @param  array<int, array{grantId: string, allocatedDays: float, allocatedMinutes: int}>  $allocations
     */
    private function replaceAllocations(string $usageId, array $allocations): void
    {
        $previous = CompensatoryGrantDayViewAllocation::query()
            ->where('usage_id', $usageId)
            ->pluck('grant_id')
            ->map(fn ($grantId) => (string) $grantId)
            ->all();

        CompensatoryGrantDayViewAllocation::query()->where('usage_id', $usageId)->delete();

        $grantIds = $previous;

        foreach ($allocations as $allocation) {
            CompensatoryGrantDayViewAllocation::query()->create([
                'usage_id' => $usageId,
                'grant_id' => $allocation['grantId'],
                'allocated_days' => $allocation['allocatedDays'],
                'allocated_minutes' => $allocation['allocatedMinutes'],
            ]);

            $grantIds[] = (string) $allocation['grantId'];
        }

        $this->recalculateGrants(array_values(array_unique($grantIds)));
    }

    /**
     * @param  string[]  $grantIds
     */
    private function recalculateGrants(array $grantIds): void
    {
        foreach ($grantIds as $grantId) {
            $this->recalculateGrant($grantId);
        }
    }

    private function recalculateGrant(string $grantId): void
    {
        $view = CompensatoryGrantDayView::query()->find($grantId);

        if ($view === null) {
            return;
        }

        $usedDays = (float) CompensatoryGrantDayViewAllocation::query()->where('grant_id', $grantId)->sum('allocated_days');
        $usedMinutes = (int) CompensatoryGrantDayViewAllocation::query()->where('grant_id', $grantId)->sum('allocated_minutes');

        $view->update([
            'used_days' => $usedDays,
            'used_minutes' => $view->granted_minutes !== null ? $usedMinutes : null,
        ]);
    }
}
