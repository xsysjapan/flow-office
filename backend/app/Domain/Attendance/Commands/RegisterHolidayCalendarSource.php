<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class RegisterHolidayCalendarSource implements Command
{
    public function __construct(
        public readonly string $name,
        public readonly string $icsUrl,
        public readonly string $registeredByUserId,
    ) {}
}
