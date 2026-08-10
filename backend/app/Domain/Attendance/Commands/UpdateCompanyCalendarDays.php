<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class UpdateCompanyCalendarDays implements Command
{
    /**
     * @param  list<array{date: string, day_type: string, is_working_day?: bool, is_legal_holiday?: bool, is_company_holiday?: bool, note?: ?string}>  $days
     */
    public function __construct(
        public readonly string $companyCalendarId,
        public readonly array $days,
        public readonly string $updatedByUserId,
    ) {}
}
