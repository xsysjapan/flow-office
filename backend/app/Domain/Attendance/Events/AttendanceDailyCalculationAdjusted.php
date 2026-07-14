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
        public readonly int $nonStatutoryOvertimeMinutes,
        public readonly int $statutoryOvertimeMinutes,
        public readonly int $lateNightMinutes,
        public readonly int $legalHolidayWorkMinutes,
        public readonly int $companyHolidayWorkMinutes,
        public readonly string $reason,
        public readonly int $adjustedByUserId,
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
            'non_statutory_overtime_minutes' => $this->nonStatutoryOvertimeMinutes,
            'statutory_overtime_minutes' => $this->statutoryOvertimeMinutes,
            'late_night_minutes' => $this->lateNightMinutes,
            'legal_holiday_work_minutes' => $this->legalHolidayWorkMinutes,
            'company_holiday_work_minutes' => $this->companyHolidayWorkMinutes,
            'reason' => $this->reason,
            'adjusted_by_user_id' => $this->adjustedByUserId,
        ];
    }
}
