<?php

namespace App\Models;

/**
 * 有給休暇の取得単位 (docs/09-usecases-paid-leave.md UC-P003)。
 */
final class PaidLeaveType
{
    public const FULL = 'full';

    public const AM_HALF = 'am_half';

    public const PM_HALF = 'pm_half';

    public const HOURLY = 'hourly';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return [self::FULL, self::AM_HALF, self::PM_HALF, self::HOURLY];
    }

    /**
     * attendance_days.work_type に反映する際の値 (docs/16-database-schema.md attendance_days)。
     */
    public static function toAttendanceWorkType(string $leaveType): string
    {
        return "paid_leave_{$leaveType}";
    }
}
