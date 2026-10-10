<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * 有給の出勤率ビュー(leave_attendance_rate_days、利用者×日付)。LeaveAttendanceRateProjectorだけが書き込み、
 * AttendanceRateAssessorが読む。work_date は保存表現(Y-m-d)のままキャストしない。
 */
#[Fillable([
    'user_id',
    'work_date',
    'is_working_day',
    'attended',
    'full_leave_kinds',
    'partial_leave_kinds',
])]
class LeaveAttendanceRateDay extends Model
{
    protected function casts(): array
    {
        return [
            'is_working_day' => 'boolean',
            'attended' => 'boolean',
            'full_leave_kinds' => 'array',
            'partial_leave_kinds' => 'array',
        ];
    }
}
