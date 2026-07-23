<?php

namespace App\Domain\PaidLeave\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class PaidLeaveRequested extends ShouldBeStored
{
    public function __construct(
        public readonly int $userId,
        public readonly string $targetDate,
        public readonly string $leaveType,
        public readonly ?float $hours,
        public readonly float $requestedDays,
        public readonly int $approverUserId,
        public readonly ?string $reason,
    ) {}
}
