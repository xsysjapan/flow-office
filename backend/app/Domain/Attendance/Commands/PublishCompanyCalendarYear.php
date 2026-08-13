<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class PublishCompanyCalendarYear implements Command
{
    public function __construct(
        public readonly string $companyCalendarYearId,
        public readonly string $publishedByUserId,
    ) {}
}
