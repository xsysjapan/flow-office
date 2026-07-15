<?php

namespace App\Models;

/**
 * attendance_leave_segments.category (docs/07-usecases-attendance.md「不就労時間の処理区分」)。
 * 有給休暇(全休・半休・時間単位)は対象外(paid_leave_requests/attendance_days.work_typeで管理)。
 */
final class AttendanceLeaveSegmentCategory
{
    public const ABSENCE = 'absence';

    public const SPECIAL_LEAVE = 'special_leave';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return [self::ABSENCE, self::SPECIAL_LEAVE];
    }
}
