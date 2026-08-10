<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class UpdateCompanyCalendarDays implements Command
{
    /**
     * @param  list<array{date: string, day_type: string, is_working_day?: bool, is_legal_holiday?: bool, is_company_holiday?: bool, is_public_holiday?: bool, public_holiday_name?: ?string, schedule_state?: string, note?: ?string}>  $days
     */
    public function __construct(
        public readonly string $companyCalendarYearId,
        public readonly array $days,
        public readonly string $updatedByUserId,
    ) {}
}
