<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class DeleteHolidayCalendarSource implements Command
{
    public function __construct(
        public readonly string $holidayCalendarSourceId,
        public readonly string $deletedByUserId,
    ) {}
}
