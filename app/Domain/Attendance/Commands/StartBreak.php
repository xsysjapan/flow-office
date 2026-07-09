<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-A002: 休憩開始する。
 */
class StartBreak implements Command
{
    public function __construct(public readonly int $userId) {}
}
