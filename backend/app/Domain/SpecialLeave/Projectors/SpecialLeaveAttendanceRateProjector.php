<?php

namespace App\Domain\SpecialLeave\Projectors;

use App\Domain\Attendance\Events\EmployeeCalendarEntryAssigned;
use App\Domain\Leave\Projectors\AbstractLeaveAttendanceRateProjector;
use App\Models\SpecialLeaveAttendanceRateAttendanceDay;
use App\Models\SpecialLeaveAttendanceRateDay;
use App\Models\SpecialLeaveAttendanceRateLeave;

/**
 * 特別休暇の出勤率の入力(special_leave_attendance_rate_*)を作る(仕様確定事項F)。処理の本体は
 * AbstractLeaveAttendanceRateProjector。特別休暇の文脈は、対象の勤務形態の絞り込みに使う work_style_id を
 * カレンダーの割当イベントから追加で書く(GrantScheduledSpecialLeaveHandler)。
 */
class SpecialLeaveAttendanceRateProjector extends AbstractLeaveAttendanceRateProjector
{
    protected function dayModel(): string
    {
        return SpecialLeaveAttendanceRateDay::class;
    }

    protected function attendanceDayModel(): string
    {
        return SpecialLeaveAttendanceRateAttendanceDay::class;
    }

    protected function leaveModel(): string
    {
        return SpecialLeaveAttendanceRateLeave::class;
    }

    protected function calendarAttributes(EmployeeCalendarEntryAssigned $event): array
    {
        return parent::calendarAttributes($event) + ['work_style_id' => $event->workStyleId];
    }
}
