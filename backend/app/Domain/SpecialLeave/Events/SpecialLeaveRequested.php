<?php

namespace App\Domain\SpecialLeave\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class SpecialLeaveRequested extends ShouldBeStored
{
    public function __construct(
        public readonly int $userId,
        public readonly int $specialLeaveTypeId,
        public readonly string $targetDate,
        public readonly string $leaveType,
        public readonly ?float $hours,
        public readonly float $requestedDays,
        public readonly int $approverUserId,
        public readonly ?string $reason,
    ) {}
}
