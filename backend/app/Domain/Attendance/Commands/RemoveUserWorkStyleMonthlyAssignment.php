<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class RemoveUserWorkStyleMonthlyAssignment implements Command
{
    public function __construct(
        public readonly string $assignmentId,
        public readonly string $removedByUserId,
    ) {}
}
