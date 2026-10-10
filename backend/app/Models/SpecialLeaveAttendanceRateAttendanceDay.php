<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * 特別休暇の出勤率の入力: 勤怠日ID→利用者・日付・退勤済みの対応(special_leave_attendance_rate_attendance_days)。
 * 勤怠のイベントから SpecialLeaveAttendanceRateProjector だけが書き込む。
 */
#[Fillable([
    'id',
    'user_id',
    'work_date',
    'clocked_out',
])]
class SpecialLeaveAttendanceRateAttendanceDay extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'clocked_out' => 'boolean',
        ];
    }
}
