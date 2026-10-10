<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * 有給の出勤率の入力: 休暇申請1件の行(leave_attendance_rate_leaves)。休暇申請文脈のイベントから
 * LeaveAttendanceRateProjectorだけが書き込む。差戻し・取消でも行は削除せず request_status を変える。
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
class LeaveAttendanceRateLeave extends Model
{
    public $timestamps = false;

}
