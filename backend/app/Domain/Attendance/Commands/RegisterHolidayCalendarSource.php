<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class RegisterHolidayCalendarSource implements Command
{
    public function __construct(
        public readonly string $name,
        public readonly string $sourceKind,
        public readonly ?string $icsUrl,
        public readonly ?string $uploadedIcsPath,
        public readonly ?string $uploadedIcsFilename,
        public readonly string $registeredByUserId,
    ) {}
}
