<?php

namespace App\Domain\PaidLeaveRequest\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * 差戻し後の再提出(paid_leave_request.resubmitted)。内容は変えず申請中に戻す。
 * 残数側がこのイベントから新しい消化記録を作れるよう、申請の内容(集約の状態から記録する)を持つ。
 */
class PaidLeaveRequestLifecycleResubmitted extends ShouldBeStored
{
    public function __construct(
        public readonly ?string $resubmittedByUserId,
        public readonly ?string $userId = null,
        public readonly ?string $targetDate = null,
        public readonly ?string $leaveType = null,
        public readonly ?float $hours = null,
        public readonly ?float $requestedDays = null,
        public readonly ?string $approverUserId = null,
        public readonly ?string $reason = null,
        public readonly ?string $requestGroupId = null,
        public readonly ?string $workflowRequestId = null,
    ) {}
}
