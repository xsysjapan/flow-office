<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class UpdateCompanyCalendar implements Command
{
    public function __construct(
        public readonly string $companyCalendarId,
        public readonly string $name,
        public readonly int $weekStartsOn,
        public readonly int $fiscalYearStartMonth,
        public readonly int $fiscalYearStartDay,
        public readonly ?string $holidayCalendarSourceId,
        public readonly string $updatedByUserId,
        public readonly ?array $weekdayHolidayPattern = null,
        public readonly ?bool $allowDailyHolidayOverride = null,
    ) {}
}
