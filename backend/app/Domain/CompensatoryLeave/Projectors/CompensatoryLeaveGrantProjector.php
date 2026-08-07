<?php

namespace App\Domain\CompensatoryLeave\Projectors;

use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveGrantCancelled;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveGrantConfirmed;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveGrantRemoved;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveGrantSynced;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequestCancelled;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveUsageDesignated;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveUsageReversed;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveUsed;
use App\Models\CompensatoryLeaveGrant;
use App\Models\CompensatoryLeaveGrantStatus;
use App\Models\CompensatoryLeaveUsage;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;

/**
 * compensatory_leave.*(付与系)イベントから compensatory_leave_grants /
 * compensatory_leave_usages を作成・更新する(SpecialLeaveGrantProjector・
 * SpecialLeaveUsageProjectorと同じ理由。代休は付与系イベントの種類が多いため1ファイルに
 * まとめる)。
 */
class CompensatoryLeaveGrantProjector extends Projector
{
    public function onCompensatoryLeaveGrantSynced(CompensatoryLeaveGrantSynced $event): void
    {
        // 同一勤怠日の実績が再編集されるたびに繰り返し記録されるため、その都度draftとして
        // 上書きする(この時点で既に使用されていることはない。draft状態のGrantのみが
        // syncの対象になるため。SyncCompensatoryLeaveGrantHandler参照)。
        CompensatoryLeaveGrant::query()->updateOrCreate(
            ['id' => $event->aggregateRootUuid()],
            [
                'user_id' => $event->userId,
                'attendance_day_id' => $event->attendanceDayId,
                'work_date' => $event->workDate,
                'granted_days' => $event->grantedDays,
                'granted_minutes' => $event->grantedMinutes,
                'used_days' => 0,
                'used_minutes' => $event->grantedMinutes !== null ? 0 : null,
                'remaining_days' => $event->grantedDays,
                'remaining_minutes' => $event->grantedMinutes,
                'status' => CompensatoryLeaveGrantStatus::DRAFT,
                'confirmed_at' => null,
                'expires_on' => null,
            ],
        );
    }

    public function onCompensatoryLeaveGrantRemoved(CompensatoryLeaveGrantRemoved $event): void
    {
        // 月次未提出(draft)のGrantのみが対象(SyncCompensatoryLeaveGrantHandler参照)。
        // 休日出勤でなくなった実績から自動導出されたGrantであり、実績として残す意味が無いため
        // 行ごと削除する。
        CompensatoryLeaveGrant::query()->whereKey($event->aggregateRootUuid())->delete();
    }

    public function onCompensatoryLeaveGrantConfirmed(CompensatoryLeaveGrantConfirmed $event): void
    {
        CompensatoryLeaveGrant::query()->whereKey($event->aggregateRootUuid())->update([
            'status' => CompensatoryLeaveGrantStatus::CONFIRMED,
            'confirmed_at' => $event->confirmedAt,
            'expires_on' => $event->expiresOn,
        ]);
    }

    public function onCompensatoryLeaveGrantCancelled(CompensatoryLeaveGrantCancelled $event): void
    {
        CompensatoryLeaveGrant::query()->whereKey($event->aggregateRootUuid())->update([
            'status' => CompensatoryLeaveGrantStatus::CANCELLED,
            'remaining_days' => 0,
            'remaining_minutes' => 0,
        ]);
    }

    /**
     * 申請時点(compensatory_leave.usage_designated)でgrant未確定・is_confirmed=falseの行が
     * 既に作られているため、compensatory_leave_grant_id(承認時に決まったgrant)が未確定な行が
     * あればその行を確定させ(PaidLeaveUsageProjector::onPaidLeaveUsedと同じ考え方)、無ければ
     * (旧イベントストアの再生等、設定行が無いケース)新規の確定済み行を作る。
     */
    public function onCompensatoryLeaveUsed(CompensatoryLeaveUsed $event): void
    {
        $grantId = $event->aggregateRootUuid();

        $pending = CompensatoryLeaveUsage::query()
            ->where('compensatory_leave_request_id', $event->compensatoryLeaveRequestId)
            ->whereNull('compensatory_leave_grant_id')
            ->first();

        if ($pending !== null) {
            $pending->update([
                'stored_event_id' => $event->storedEventId(),
                'compensatory_leave_grant_id' => $grantId,
                'used_days' => $event->usedDays,
                'used_minutes' => $event->usedMinutes,
                'is_confirmed' => true,
            ]);

            $this->recalculate($grantId);

            return;
        }

        CompensatoryLeaveUsage::query()->updateOrCreate(
            ['stored_event_id' => $event->storedEventId()],
            [
                'user_id' => $event->userId,
                'attendance_day_id' => $event->attendanceDayId,
                'compensatory_leave_grant_id' => $grantId,
                'compensatory_leave_request_id' => $event->compensatoryLeaveRequestId,
                'used_on' => $event->usedOn,
                'used_days' => $event->usedDays,
                'used_minutes' => $event->usedMinutes,
                'usage_type' => $event->usageType,
                'is_confirmed' => true,
            ],
        );

        $this->recalculate($grantId);
    }

    public function onCompensatoryLeaveUsageDesignated(CompensatoryLeaveUsageDesignated $event): void
    {
        CompensatoryLeaveUsage::query()->updateOrCreate(
            ['stored_event_id' => $event->storedEventId()],
            [
                'user_id' => $event->userId,
                'attendance_day_id' => $event->attendanceDayId,
                'compensatory_leave_grant_id' => null,
                'compensatory_leave_request_id' => $event->aggregateRootUuid(),
                'used_on' => $event->usedOn,
                'used_days' => $event->usedDays,
                'used_minutes' => $event->usedMinutes,
                'usage_type' => $event->usageType,
                'is_confirmed' => false,
            ],
        );
    }

    /**
     * 承認済みの代休消化申請が取り消された際、対応するusage行を削除し、used_days/
     * used_minutes/remaining_days/remaining_minutesを再計算する(compensatory_leave_usages
     * は「現時点で有効な消化」の一覧であり、履歴はstored_eventsに残る。PaidLeaveUsageProjector
     * ::onPaidLeaveUsageReversedと同じ考え方)。
     */
    public function onCompensatoryLeaveUsageReversed(CompensatoryLeaveUsageReversed $event): void
    {
        $grantId = $event->aggregateRootUuid();

        CompensatoryLeaveUsage::query()
            ->where('compensatory_leave_grant_id', $grantId)
            ->where('compensatory_leave_request_id', $event->compensatoryLeaveRequestId)
            ->delete();

        $this->recalculate($grantId);
    }

    /**
     * 未承認(submitted)のまま取消された場合、grant消化はまだ発生していないため
     * (compensatory_leave.usage_reversedは発行されない)、承認前の設定行
     * (is_confirmed=false)をここで削除する。承認済みの取消はonCompensatoryLeaveUsageReversedが
     * 確定済み行を削除するため、ここではis_confirmed=falseの行のみを対象にする
     * (重複削除を避ける。PaidLeaveUsageProjector::onPaidLeaveRequestCancelledと同じ考え方)。
     */
    public function onCompensatoryLeaveRequestCancelled(CompensatoryLeaveRequestCancelled $event): void
    {
        CompensatoryLeaveUsage::query()
            ->where('compensatory_leave_request_id', $event->aggregateRootUuid())
            ->where('is_confirmed', false)
            ->delete();
    }

    /**
     * used_days/used_minutesは、この集約に記録された全compensatory_leave.usedイベントの
     * usedDays/usedMinutes合計からcompensatory_leave.usage_reversedイベントの合計を差し引いた
     * 値を都度再計算する(PaidLeaveGrantProjector::recalculateと同じ考え方。Projectorの
     * 再適用・複数回実行に対して冪等にするため)。
     */
    private function recalculate(string $grantId): void
    {
        $grant = CompensatoryLeaveGrant::query()->findOrFail($grantId);

        $usedDays = $this->totalUsed($grantId, 'usedDays') - $this->totalReversed($grantId, 'usedDays');
        $usedMinutes = $grant->granted_minutes !== null
            ? (int) ($this->totalUsed($grantId, 'usedMinutes') - $this->totalReversed($grantId, 'usedMinutes'))
            : null;

        $grant->update([
            'used_days' => $usedDays,
            'used_minutes' => $usedMinutes,
            'remaining_days' => (float) $grant->granted_days - $usedDays,
            'remaining_minutes' => $grant->granted_minutes !== null ? (int) $grant->granted_minutes - $usedMinutes : null,
        ]);
    }

    private function totalUsed(string $grantId, string $property): float
    {
        return (float) EloquentStoredEvent::query()
            ->where('aggregate_uuid', $grantId)
            ->where('event_class', 'compensatory_leave.used')
            ->get()
            ->sum(fn (EloquentStoredEvent $event) => (float) ($event->event_properties[$property] ?? 0));
    }

    private function totalReversed(string $grantId, string $property): float
    {
        return (float) EloquentStoredEvent::query()
            ->where('aggregate_uuid', $grantId)
            ->where('event_class', 'compensatory_leave.usage_reversed')
            ->get()
            ->sum(fn (EloquentStoredEvent $event) => (float) ($event->event_properties[$property] ?? 0));
    }
}
