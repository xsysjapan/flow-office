<?php

namespace App\Domain\PaidLeaveSchedule\Projectors;

use App\Domain\Leave\Projectors\AbstractLeaveAttendanceRateProjector;
use App\Models\LeaveAttendanceRateAttendanceDay;
use App\Models\LeaveAttendanceRateDay;
use App\Models\LeaveAttendanceRateLeave;

/**
 * 有給の出勤率の入力(leave_attendance_rate_days・leave_attendance_rate_leaves・
 * leave_attendance_rate_attendance_days)を作る(仕様確定事項F)。処理の本体は AbstractLeaveAttendanceRateProjector、
 * ここは有給の文脈が持つ表・モデルだけを渡す。Spatieの自動検出の対象はこの具象クラス。
 */
class LeaveAttendanceRateProjector extends AbstractLeaveAttendanceRateProjector
{
    protected function dayModel(): string
    {
        return LeaveAttendanceRateDay::class;
    }

    protected function attendanceDayModel(): string
    {
        return LeaveAttendanceRateAttendanceDay::class;
    }

    protected function leaveModel(): string
    {
        return LeaveAttendanceRateLeave::class;
    }
}
