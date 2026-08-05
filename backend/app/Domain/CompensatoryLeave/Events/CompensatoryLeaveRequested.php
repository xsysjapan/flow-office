<?php

namespace App\Domain\CompensatoryLeave\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class CompensatoryLeaveRequested extends ShouldBeStored
{
    public function __construct(
        public readonly string $userId,
        public readonly string $targetDate,
        public readonly string $leaveType,
        public readonly ?float $hours,
        public readonly float $requestedDays,
        public readonly ?int $requestedMinutes,
        public readonly string $approverUserId,
        public readonly ?string $reason,
    ) {}
}
