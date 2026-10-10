<?php

namespace App\Domain\PaidLeaveRequest\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * 有給申請の取消(paid_leave_request.cancelled)。申請中・差戻し中・承認済みから遷移する。
 * userId は申請者(残数側の口座を特定するため)。
 */
class PaidLeaveRequestLifecycleCancelled extends ShouldBeStored
{
    public function __construct(
        public readonly ?string $cancelledByUserId,
        public readonly ?string $reason,
        public readonly ?string $userId = null,
    ) {}
}
