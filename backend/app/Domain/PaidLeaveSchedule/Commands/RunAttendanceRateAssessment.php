<?php

namespace App\Domain\PaidLeaveSchedule\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class RunAttendanceRateAssessment implements Command
{
    public function __construct(
        public readonly string $userId,
        public readonly string $entryId,
    ) {}
}
