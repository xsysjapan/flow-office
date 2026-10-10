<?php

namespace App\Domain\PaidLeaveRequest\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * 有給申請の承認(paid_leave_request.approved)。
 */
class PaidLeaveRequestLifecycleApproved extends ShouldBeStored
{
    public function __construct(
        public readonly ?string $approvedByUserId,
    ) {}
}
