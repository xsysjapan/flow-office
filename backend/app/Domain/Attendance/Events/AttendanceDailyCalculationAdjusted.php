<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * attendance_day.daily_calculation_adjusted
 *
 * AttendanceDailyCalculationProjectorはこのイベントのpayloadを、直前のattendance_day.calculated
 * が作った行に上書きで反映する(is_manually_adjusted=trueにする)。その後日次実績が再編集され
 * attendance_day.calculatedが再発生すると、この補正は解除される。
 */
class AttendanceDailyCalculationAdjusted extends ShouldBeStored
{
    public function __construct(
        public readonly int $prescribedWorkMinutes,
        public readonly int $statutoryWithinOvertimeMinutes,
        public readonly int $statutoryExcessOvertimeMinutes,
        public readonly int $legalHolidayWorkMinutes,
        public readonly int $prescribedHolidayWorkMinutes,
        public readonly int $lateNightPrescribedWorkMinutes,
        public readonly int $lateNightStatutoryWithinOvertimeMinutes,
        public readonly int $lateNightStatutoryExcessOvertimeMinutes,
        public readonly int $lateNightLegalHolidayWorkMinutes,
        public readonly string $reason,
        public readonly string $adjustedByUserId,
    ) {}
}
