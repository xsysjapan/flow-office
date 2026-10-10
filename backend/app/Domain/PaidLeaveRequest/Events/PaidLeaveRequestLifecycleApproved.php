<?php

namespace App\Domain\PaidLeaveRequest\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * 有給申請の承認(paid_leave_request.approved)。
 * userId は申請者(残数側の口座を特定するため。集約の状態から記録する)。
 */
class PaidLeaveRequestLifecycleApproved extends ShouldBeStored
{
    public function __construct(
        public readonly ?string $approvedByUserId,
        public readonly ?string $userId = null,
    ) {}
}
