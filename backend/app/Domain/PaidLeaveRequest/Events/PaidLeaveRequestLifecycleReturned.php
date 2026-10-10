<?php

namespace App\Domain\PaidLeaveRequest\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * 有給申請の差戻し(paid_leave_request.returned)。
 */
class PaidLeaveRequestLifecycleReturned extends ShouldBeStored
{
    public function __construct(
        public readonly ?string $returnedByUserId,
        public readonly ?string $comment,
    ) {}
}
