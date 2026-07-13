<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class PublishWorkCalendar implements Command
{
    public function __construct(
        public readonly int $workCalendarId,
        public readonly int $publishedByUserId,
    ) {}
}
