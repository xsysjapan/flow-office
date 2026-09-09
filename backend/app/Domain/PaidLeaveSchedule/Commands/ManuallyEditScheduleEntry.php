<?php

namespace App\Domain\PaidLeaveSchedule\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class ManuallyEditScheduleEntry implements Command
{
    public function __construct(
        public readonly string $userId,
        public readonly string $entryId,
        public readonly ?string $category,
        public readonly ?float $candidateGrantDays,
        public readonly string $reason,
        public readonly string $operatorUserId,
    ) {}
}
