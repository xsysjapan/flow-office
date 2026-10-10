<?php

namespace App\Domain\CompensatoryLeave\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * 差戻し後の再提出(compensatory_leave.request_resubmitted)。内容は変えず申請中に戻す。
 * 残数側・勤怠側がこのイベントから新しい消化記録・勤怠の反映を行えるよう、申請の内容を集約の状態から記録する。
 */
class CompensatoryLeaveRequestResubmitted extends ShouldBeStored
{
    public function __construct(
        public readonly ?string $resubmittedByUserId,
        public readonly ?string $userId = null,
        public readonly ?string $targetDate = null,
        public readonly ?string $leaveType = null,
        public readonly ?float $hours = null,
        public readonly ?float $requestedDays = null,
        public readonly ?int $requestedMinutes = null,
        public readonly ?string $approverUserId = null,
        public readonly ?string $reason = null,
        public readonly ?string $requestGroupId = null,
    ) {}
}
