<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class AssignUserWorkStyleForMonth implements Command
{
    public function __construct(
        public readonly int $userId,
        public readonly string $yearMonth,
        public readonly int $workStyleId,
        public readonly int $assignedByUserId,
    ) {}
}
