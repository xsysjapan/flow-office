<?php

namespace App\Domain\CompensatoryLeave\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * compensatory_leave.manually_granted
 *
 * 管理者が休日出勤の対象日を指定して代休を手動付与した(GrantCompensatoryLeaveHandler参照)。
 * 勤怠実績からの自動導出(compensatory_leave.grant_synced → draft → grant_confirmed)とは
 * 異なり、承認不要でこのイベント1件だけでstatus=confirmedの行を作成する。
 */
class CompensatoryLeaveManuallyGranted extends ShouldBeStored
{
    public function __construct(
        public readonly string $userId,
        public readonly string $workDate,
        public readonly float $grantedDays,
        public readonly ?int $grantedMinutes,
        public readonly ?string $expiresOn,
        public readonly ?string $grantReason,
    ) {}
}
