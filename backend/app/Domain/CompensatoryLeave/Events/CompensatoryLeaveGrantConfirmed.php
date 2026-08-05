<?php

namespace App\Domain\CompensatoryLeave\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * compensatory_leave.grant_confirmed
 *
 * 月次勤怠の提出時に、対象月に属するドラフト状態のGrantを確定する
 * (ConfirmCompensatoryLeaveGrantsForMonthHandler参照)。expiresOnは
 * system_settings.compensatory_leave_valid_daysが設定されていればconfirmedAtからのN日後、
 * nullなら無期限(null)。
 */
class CompensatoryLeaveGrantConfirmed extends ShouldBeStored
{
    public function __construct(
        public readonly string $confirmedAt,
        public readonly ?string $expiresOn,
    ) {}
}
