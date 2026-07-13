<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SystemSettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'default_timezone' => $this->default_timezone,
            'attendance_submission_deadline_day' => $this->attendance_submission_deadline_day,
            'attendance_month_close_deadline_day' => $this->attendance_month_close_deadline_day,
        ];
    }
}
