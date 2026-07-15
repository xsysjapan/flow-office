<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceDailyCalculationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'planned_work_minutes' => $this->planned_work_minutes,
            'work_minutes' => $this->work_minutes,
            'deemed_work_minutes' => $this->deemed_work_minutes,
            'payroll_work_minutes' => $this->payroll_work_minutes,
            'prescribed_work_minutes' => $this->prescribed_work_minutes,
            'statutory_within_overtime_minutes' => $this->statutory_within_overtime_minutes,
            'statutory_excess_overtime_minutes' => $this->statutory_excess_overtime_minutes,
            'late_night_work_minutes' => $this->late_night_work_minutes,
            'late_night_prescribed_work_minutes' => $this->late_night_prescribed_work_minutes,
            'late_night_statutory_within_overtime_minutes' => $this->late_night_statutory_within_overtime_minutes,
            'late_night_statutory_excess_overtime_minutes' => $this->late_night_statutory_excess_overtime_minutes,
            'legal_holiday_work_minutes' => $this->legal_holiday_work_minutes,
            'prescribed_holiday_work_minutes' => $this->prescribed_holiday_work_minutes,
            'late_night_legal_holiday_work_minutes' => $this->late_night_legal_holiday_work_minutes,
            'core_time_violation' => $this->core_time_violation,
            'absence_minutes' => $this->absence_minutes,
            'special_leave_minutes' => $this->special_leave_minutes,
            'paid_leave_days' => (float) $this->paid_leave_days,
            'paid_leave_minutes' => $this->paid_leave_minutes,
            'is_manually_adjusted' => $this->is_manually_adjusted,
        ];
    }
}
