<?php

namespace App\Domain\PaidLeave\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-P003: 有給を申請する。
 */
class RequestPaidLeave implements Command
{
    public function __construct(
        public readonly string $userId,
        public readonly string $targetDate,
        public readonly string $leaveType,
        public readonly ?float $hours,
        public readonly string $approverUserId,
        public readonly ?string $reason,
    ) {}
}
