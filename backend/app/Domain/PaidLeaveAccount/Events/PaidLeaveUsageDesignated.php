<?php

namespace App\Domain\PaidLeaveAccount\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class PaidLeaveUsageDesignated extends ShouldBeStored
{
    public function __construct(
        public readonly string $usageId,
        public readonly ?string $workflowRequestId,
        public readonly ?string $attendanceDayId,
        public readonly string $usedOn,
        public readonly float $usedDays,
        public readonly string $usageType = 'full',
        public readonly ?string $paidLeaveRequestId = null,
        public readonly ?string $approverUserId = null,
        public readonly ?string $reason = null,
        public readonly ?string $requestGroupId = null,
        public readonly ?float $hours = null,
    ) {}
}
