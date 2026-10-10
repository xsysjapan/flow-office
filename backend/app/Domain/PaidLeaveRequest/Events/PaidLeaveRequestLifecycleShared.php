<?php

namespace App\Domain\PaidLeaveRequest\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * 有給申請を申請・承認文脈のワークフローとして提出したことの記録(paid_leave_request.shared)。
 */
class PaidLeaveRequestLifecycleShared extends ShouldBeStored
{
    public function __construct(
        public readonly string $workflowRequestId,
    ) {}
}
