<?php

namespace App\Domain\PaidLeaveRequest\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * 有給申請の差戻し(paid_leave_request.returned)。userId は申請者(残数側の口座を特定するため)。
 */
class PaidLeaveRequestLifecycleReturned extends ShouldBeStored
{
    public function __construct(
        public readonly ?string $returnedByUserId,
        public readonly ?string $comment,
        public readonly ?string $userId = null,
    ) {}
}
