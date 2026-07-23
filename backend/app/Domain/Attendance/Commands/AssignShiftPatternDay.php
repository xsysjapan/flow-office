<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 3交代制シフト表: 社員の特定日にシフトパターンを割り当てる(UC-C004 手順3)。
 */
class AssignShiftPatternDay implements Command
{
    public function __construct(
        public readonly string $userId,
        public readonly string $workDate,
        public readonly string $workStyleId,
        public readonly string $shiftPatternId,
        public readonly bool $isLegalHoliday,
        public readonly bool $isCompanyHoliday,
        public readonly string $assignedByUserId,
    ) {}
}
