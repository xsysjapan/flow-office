<?php

namespace App\Domain\PaidLeaveSchedule\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * hire_date/usage_start_date/work_style_id割当/独自ルール変更等を検知したReactor
 * (Phase C)から発行される。過去確定・個別修正エントリの保護は
 * `PaidLeaveScheduleAggregate::supersedeEntry()`側で行う。
 */
class RecalculateFutureSchedule implements Command
{
    public function __construct(
        public readonly string $userId,
        public readonly string $reason,
    ) {}
}
