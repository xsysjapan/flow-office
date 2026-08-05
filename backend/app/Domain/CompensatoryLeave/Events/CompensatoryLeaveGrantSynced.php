<?php

namespace App\Domain\CompensatoryLeave\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * compensatory_leave.grant_synced
 *
 * 休日出勤の勤怠実績(attendance_days)から代休Grantを自動導出・更新する
 * (App\Domain\CompensatoryLeave\Handlers\SyncCompensatoryLeaveGrantHandler参照)。
 * attendance_day_idをユニークキーとしてupsertされるため、同じ日の実績が再編集されるたびに
 * このイベントが繰り返し記録され、CompensatoryLeaveGrantProjectorが都度上書きする。
 */
class CompensatoryLeaveGrantSynced extends ShouldBeStored
{
    public function __construct(
        public readonly string $userId,
        public readonly string $attendanceDayId,
        public readonly string $workDate,
        public readonly float $grantedDays,
        public readonly ?int $grantedMinutes,
    ) {}
}
