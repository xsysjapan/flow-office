<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class CreateWorkCalendar implements Command
{
    public function __construct(
        public readonly string $name,
        public readonly int $fiscalYear,
        public readonly string $startsOn,
        public readonly string $endsOn,
        public readonly int $weekStartsOn,
        public readonly string $createdByUserId,
    ) {}
}
