<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class AssignEmployeeRotation implements Command
{
    public function __construct(
        public readonly string $userId,
        public readonly string $rotationPatternId,
        public readonly string $rotationStartDate,
        public readonly int $rotationStartPosition,
        public readonly string $assignedByUserId,
    ) {}
}
