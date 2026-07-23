<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 会社カレンダーの日区分をもとに、指定期間分の勤務予定を一括生成する(UC-C003)。
 */
class GenerateEmployeeShiftAssignments implements Command
{
    public function __construct(
        public readonly string $userId,
        public readonly int $workStyleId,
        public readonly string $from,
        public readonly string $to,
        public readonly string $generatedByUserId,
    ) {}
}
