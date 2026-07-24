<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-A001: 出勤する。
 */
class ClockIn implements Command
{
    public function __construct(public readonly string $userId) {}
}
