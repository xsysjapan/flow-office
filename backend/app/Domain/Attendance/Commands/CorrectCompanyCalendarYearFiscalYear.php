<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class CorrectCompanyCalendarYearFiscalYear implements Command
{
    public function __construct(
        public readonly string $companyCalendarYearId,
        public readonly int $fiscalYear,
        public readonly string $startsOn,
        public readonly string $endsOn,
        public readonly string $correctedByUserId,
        public readonly ?string $reason,
    ) {}
}
