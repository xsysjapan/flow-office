<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 日次登録後、区分ごとの時間(所定内労働・残業・深夜・休日労働)を手動で補正する。
 */
class AdjustAttendanceDailyCalculation implements Command
{
    public function __construct(
        public readonly int $attendanceDayId,
        public readonly int $prescribedWorkMinutes,
        public readonly int $nonStatutoryOvertimeMinutes,
        public readonly int $statutoryOvertimeMinutes,
        public readonly int $lateNightMinutes,
        public readonly int $legalHolidayWorkMinutes,
        public readonly int $companyHolidayWorkMinutes,
        public readonly string $reason,
        public readonly int $adjustedByUserId,
    ) {}
}
