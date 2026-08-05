<?php

namespace App\Domain\CompensatoryLeave\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class ConfirmCompensatoryLeaveGrantsForMonth implements Command
{
    public function __construct(
        public readonly string $userId,
        public readonly string $yearMonth,
        public readonly string $submittedAt,
    ) {}
}
