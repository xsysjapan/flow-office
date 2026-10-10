<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * 特別休暇の出勤率の入力: 休暇申請1件の行(special_leave_attendance_rate_leaves)。休暇申請文脈のイベントから
 * SpecialLeaveAttendanceRateProjectorだけが書き込む。
 */
#[Fillable([
    'leave_kind',
    'leave_request_id',
    'user_id',
    'work_date',
    'unit',
    'usage_id',
    'workflow_request_id',
    'request_status',
    'source',
])]
class SpecialLeaveAttendanceRateLeave extends Model
{
}
