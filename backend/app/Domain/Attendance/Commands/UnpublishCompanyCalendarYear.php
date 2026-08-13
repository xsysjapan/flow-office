<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class UnpublishCompanyCalendarYear implements Command
{
    public function __construct(
        public readonly string $companyCalendarYearId,
        public readonly string $unpublishedByUserId,
    ) {}
}
