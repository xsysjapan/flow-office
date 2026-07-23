<?php

namespace App\Domain\Attendance\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

/**
 * attendance.daily_calculation_adjusted
 *
 * AttendanceDailyCalculationProjectorはこのイベントのpayloadを、直前のattendance.day_calculated
 * が作った行に上書きで反映する(is_manually_adjusted=trueにする)。その後日次実績が再編集され
 * attendance.day_calculatedが再発生すると、この補正は解除される。
 */
class AttendanceDailyCalculationAdjusted implements DomainEvent
{
    public function __construct(
        public readonly int $attendanceDayId,
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

    public function eventType(): string
    {
        return 'attendance.daily_calculation_adjusted';
    }

    public function payload(): array
    {
        return [
            'attendance_day_id' => $this->attendanceDayId,
            'prescribed_work_minutes' => $this->prescribedWorkMinutes,
            'statutory_within_overtime_minutes' => $this->statutoryWithinOvertimeMinutes,
            'statutory_excess_overtime_minutes' => $this->statutoryExcessOvertimeMinutes,
            'late_night_work_minutes' => $this->lateNightPrescribedWorkMinutes
                + $this->lateNightStatutoryWithinOvertimeMinutes
                + $this->lateNightStatutoryExcessOvertimeMinutes
                + $this->lateNightLegalHolidayWorkMinutes,
            'late_night_prescribed_work_minutes' => $this->lateNightPrescribedWorkMinutes,
            'late_night_statutory_within_overtime_minutes' => $this->lateNightStatutoryWithinOvertimeMinutes,
            'late_night_statutory_excess_overtime_minutes' => $this->lateNightStatutoryExcessOvertimeMinutes,
            'legal_holiday_work_minutes' => $this->legalHolidayWorkMinutes,
            'prescribed_holiday_work_minutes' => $this->prescribedHolidayWorkMinutes,
            'late_night_legal_holiday_work_minutes' => $this->lateNightLegalHolidayWorkMinutes,
            'reason' => $this->reason,
            'adjusted_by_user_id' => $this->adjustedByUserId,
        ];
    }
}
