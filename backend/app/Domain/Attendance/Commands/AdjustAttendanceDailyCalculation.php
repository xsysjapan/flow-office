<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 日次登録後、区分ごとの時間(所定労働・残業・深夜・休日労働)を手動で補正する。
 */
class AdjustAttendanceDailyCalculation implements Command
{
    public function __construct(
        public readonly int $attendanceDayId,
        public readonly int $prescribedWorkMinutes,
        public readonly int $statutoryWithinOvertimeMinutes,
        public readonly int $statutoryExcessOvertimeMinutes,
        public readonly int $legalHolidayWorkMinutes,
        public readonly int $lateNightPrescribedWorkMinutes,
        public readonly int $lateNightStatutoryWithinOvertimeMinutes,
        public readonly int $lateNightStatutoryExcessOvertimeMinutes,
        public readonly int $lateNightLegalHolidayWorkMinutes,
        public readonly string $reason,
        public readonly int $adjustedByUserId,
    ) {}
}
