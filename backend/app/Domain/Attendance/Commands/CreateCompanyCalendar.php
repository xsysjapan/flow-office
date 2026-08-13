<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class CreateCompanyCalendar implements Command
{
    public function __construct(
        public readonly string $name,
        public readonly int $weekStartsOn,
        public readonly int $fiscalYearStartMonth,
        public readonly int $fiscalYearStartDay,
        public readonly string $createdByUserId,
        public readonly ?array $weekdayHolidayPattern = null,
        public readonly ?string $holidayCalendarSourceId = null,
        public readonly ?bool $allowDailyHolidayOverride = null,
    ) {}
}
