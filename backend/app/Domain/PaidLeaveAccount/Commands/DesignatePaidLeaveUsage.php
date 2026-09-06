<?php

namespace App\Domain\PaidLeaveAccount\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class DesignatePaidLeaveUsage implements Command
{
    public function __construct(
        public readonly string $userId,
        public readonly ?string $workflowRequestId,
        public readonly ?string $attendanceDayId,
        public readonly string $usedOn,
        public readonly float $usedDays,
    ) {}
}
