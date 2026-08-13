<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class DeleteCompanyCalendar implements Command
{
    public function __construct(
        public readonly string $companyCalendarId,
        public readonly string $deletedByUserId,
    ) {}
}
