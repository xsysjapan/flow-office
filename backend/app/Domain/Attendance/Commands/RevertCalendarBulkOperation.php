<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class RevertCalendarBulkOperation implements Command
{
    public function __construct(
        public readonly string $calendarBulkOperationId,
        public readonly string $revertedByUserId,
    ) {}
}
