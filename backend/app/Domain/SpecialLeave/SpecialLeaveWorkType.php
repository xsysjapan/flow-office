<?php

namespace App\Domain\SpecialLeave;

use App\Models\PaidLeaveType;

/**
 * 特別休暇の取得単位(全休/半休/時間休)は有給と同じ概念(PaidLeaveType::values())を
 * そのまま再利用する。attendance_days.work_type に反映する際のプレフィックスのみ
 * 有給と区別する。
 */
final class SpecialLeaveWorkType
{
    private const PREFIX = 'special_leave_';

    public static function toAttendanceWorkType(string $leaveType): string
    {
        return self::PREFIX.$leaveType;
    }

    public static function isSpecialLeaveWorkType(?string $workType): bool
    {
        return $workType !== null && str_starts_with($workType, self::PREFIX);
    }

    /** work_typeから全休/半休/時間休の単位部分を取り出す(PaidLeaveType::values()のいずれか)。 */
    public static function unitFromWorkType(string $workType): string
    {
        return substr($workType, strlen(self::PREFIX));
    }
}
