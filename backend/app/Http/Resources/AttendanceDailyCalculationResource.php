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
            'actual_work_minutes' => $this->actual_work_minutes,
            'deemed_work_minutes' => $this->deemed_work_minutes,
            'payroll_work_minutes' => $this->payroll_work_minutes,
            'prescribed_work_minutes' => $this->prescribed_work_minutes,
            'non_statutory_overtime_minutes' => $this->non_statutory_overtime_minutes,
            'statutory_overtime_minutes' => $this->statutory_overtime_minutes,
            'late_night_minutes' => $this->late_night_minutes,
            'statutory_overtime_late_night_minutes' => $this->statutory_overtime_late_night_minutes,
            'legal_holiday_work_minutes' => $this->legal_holiday_work_minutes,
            'company_holiday_work_minutes' => $this->company_holiday_work_minutes,
            'legal_holiday_late_night_minutes' => $this->legal_holiday_late_night_minutes,
            'core_time_violation' => $this->core_time_violation,
        ];
    }
}
