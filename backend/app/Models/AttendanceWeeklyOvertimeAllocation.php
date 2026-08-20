<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['attendance_day_id', 'week_start_date', 'prescribed_minutes', 'non_prescribed_minutes', 'late_night_prescribed_minutes', 'late_night_non_prescribed_minutes', 'allocated_by_user_id'])]
class AttendanceWeeklyOvertimeAllocation extends Model {}
