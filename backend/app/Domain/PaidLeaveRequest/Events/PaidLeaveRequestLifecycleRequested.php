<?php

namespace App\Domain\PaidLeaveRequest\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * 有給申請の申請(paid_leave_request.requested)。本変更以後に新規作成された申請のみ記録する。
 */
class PaidLeaveRequestLifecycleRequested extends ShouldBeStored
{
    public function __construct(
        public readonly string $userId,
        public readonly string $targetDate,
        public readonly string $leaveType,
        public readonly ?float $hours,
        public readonly float $requestedDays,
        public readonly ?string $approverUserId,
        public readonly ?string $reason,
        public readonly ?string $requestGroupId = null,
        public readonly ?string $workflowRequestId = null,
    ) {}
}
