<?php

namespace App\Domain\Attendance\Support;

use App\Models\PaidLeaveType;

/**
 * 有効な休暇(休暇ビューの申請中・承認済み)から、その日が「全休」かを判定する(仕様確定事項I・論点9)。
 * UI・DBに依存しない純粋な判定。
 *
 * 全休とみなす条件(休暇の種類=有給・特別・代休は問わない):
 * - 全休(full)の休暇が1つでもある。
 * - 午前半休(am_half)と午後半休(pm_half)が(種類を問わず)そろう。
 *
 * 時間休(hourly)は全休とみなさない。半休が1つだけの日も全休とはみなさない。
 *
 * 出勤可否・打刻の取り込み・打刻漏れ警告・未出勤件数・今日の表示は、この判定で全休かどうかを見る
 * (勤怠日の status や work_type を見ない)。
 */
final class FullDayLeavePolicy
{
    /**
     * @param  list<array{unit: string}>  $activeLeaves  その日の有効な休暇(AttendanceDayLeaves の戻り値)
     */
    public static function isFullDay(array $activeLeaves): bool
    {
        $hasFullDayLeave = false;
        $amHalfCount = 0;
        $pmHalfCount = 0;

        foreach ($activeLeaves as $leave) {
            match ($leave['unit']) {
                PaidLeaveType::FULL => $hasFullDayLeave = true,
                PaidLeaveType::AM_HALF => $amHalfCount++,
                PaidLeaveType::PM_HALF => $pmHalfCount++,
                default => null,
            };
        }

        return $hasFullDayLeave || ($amHalfCount > 0 && $pmHalfCount > 0);
    }
}
