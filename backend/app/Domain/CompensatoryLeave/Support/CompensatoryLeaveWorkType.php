<?php

namespace App\Domain\CompensatoryLeave\Support;

use App\Models\PaidLeaveType;

/**
 * 代休の取得単位(全休/半休/時間休)は有給・特別休暇と同じ概念(PaidLeaveType::values())を
 * そのまま再利用する。attendance_days.work_type に反映する際のプレフィックスのみ区別する
 * (SpecialLeaveWorkTypeと同じ考え方)。
 */
final class CompensatoryLeaveWorkType
{
    private const PREFIX = 'compensatory_leave_';

    public static function toAttendanceWorkType(string $leaveType): string
    {
        return self::PREFIX.$leaveType;
    }

    public static function isCompensatoryLeaveWorkType(?string $workType): bool
    {
        return $workType !== null && str_starts_with($workType, self::PREFIX);
    }

    public static function unitFromWorkType(string $workType): string
    {
        return substr($workType, strlen(self::PREFIX));
    }
}
