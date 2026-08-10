<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class CreateCompanyCalendarYear implements Command
{
    public function __construct(
        public readonly string $companyCalendarId,
        public readonly int $fiscalYear,
        public readonly string $startsOn,
        public readonly string $endsOn,
        public readonly string $generatedFrom,
        public readonly ?string $createdByUserId,
    ) {}
}
