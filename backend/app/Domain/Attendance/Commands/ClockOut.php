<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-A004: 退勤する。
 */
class ClockOut implements Command
{
    public function __construct(public readonly int $userId) {}
}
