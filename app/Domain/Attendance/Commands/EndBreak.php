<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-A003: 休憩終了する。
 */
class EndBreak implements Command
{
    public function __construct(public readonly int $userId) {}
}
