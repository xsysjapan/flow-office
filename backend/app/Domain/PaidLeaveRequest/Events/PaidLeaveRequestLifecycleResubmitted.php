<?php

namespace App\Domain\PaidLeaveRequest\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * 差戻し後の再提出(paid_leave_request.resubmitted)。内容は変えず申請中に戻す。
 */
class PaidLeaveRequestLifecycleResubmitted extends ShouldBeStored
{
    public function __construct(
        public readonly ?string $resubmittedByUserId,
    ) {}
}
