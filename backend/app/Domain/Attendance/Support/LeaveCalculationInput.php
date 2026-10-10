<?php

namespace App\Domain\Attendance\Support;

use App\Models\AttendanceDayLeave;
use App\Models\PaidLeaveType;

/**
 * 勤怠の日次計算に渡す休暇の入力(休暇ビュー→計算への変換)。純粋な値変換でDBに依存しない。
 *
 * 現行 AttendanceCalculator::calculate の休暇まわり(AttendanceCalculator.php:125-161)と同じ値になるように定める。
 * - 有給・特別の日数: 全休1.0・半休0.5。代休は日数に数えない。
 * - 有給・特別の時間休分数: 時間休(hourly)のminutesの合計。代休は数えない。
 * - 所定労働時間: 全休がある日、または午前半休と午後半休がそろう日はP(全休と同じ扱い)。
 *   半休が1つだけ(種類を問わず)の日は intdiv(P,2)。それ以外はP。
 *   (現行は work_type が `_am_half`/`_pm_half` で終わる日を intdiv(P,2) としていた。単一休暇ではこれと同じ値になる)
 * - isFullDayLeave: 全休がある、または午前半休と午後半休がそろう(全休・打刻・警告の判定に使う)。
 *
 * 各休暇は ['leave_kind' => paid|special|compensatory, 'unit' => full|am_half|pm_half|hourly, 'minutes' => ?int]
 * の配列。
 */
final class LeaveCalculationInput
{
    private function __construct(
        public readonly float $paidLeaveDays,
        public readonly float $specialLeaveDays,
        public readonly int $paidLeaveMinutes,
        public readonly int $specialLeaveMinutes,
        public readonly int $effectivePrescribedMinutes,
        public readonly bool $isFullDayLeave,
    ) {}

    /**
     * @param  list<array{leave_kind: string, unit: string, minutes?: ?int}>  $activeLeaves
     */
    public static function from(array $activeLeaves, int $prescribedMinutes): self
    {
        $paidLeaveDays = 0.0;
        $specialLeaveDays = 0.0;
        $paidLeaveMinutes = 0;
        $specialLeaveMinutes = 0;
        $hasFullDayLeave = false;
        $amHalfCount = 0;
        $pmHalfCount = 0;

        foreach ($activeLeaves as $leave) {
            $kind = $leave['leave_kind'];
            $unit = $leave['unit'];
            $minutes = (int) ($leave['minutes'] ?? 0);

            if ($unit === PaidLeaveType::FULL) {
                $hasFullDayLeave = true;
            } elseif ($unit === PaidLeaveType::AM_HALF) {
                $amHalfCount++;
            } elseif ($unit === PaidLeaveType::PM_HALF) {
                $pmHalfCount++;
            }

            $days = match ($unit) {
                PaidLeaveType::FULL => 1.0,
                PaidLeaveType::AM_HALF, PaidLeaveType::PM_HALF => 0.5,
                default => 0.0,
            };

            if ($kind === AttendanceDayLeave::KIND_PAID) {
                $paidLeaveDays += $days;
                if ($unit === PaidLeaveType::HOURLY) {
                    $paidLeaveMinutes += $minutes;
                }
            } elseif ($kind === AttendanceDayLeave::KIND_SPECIAL) {
                $specialLeaveDays += $days;
                if ($unit === PaidLeaveType::HOURLY) {
                    $specialLeaveMinutes += $minutes;
                }
            }
            // 代休(compensatory)は日数・時間休分数に数えない(現行どおり)。
        }

        $isFullDayLeave = $hasFullDayLeave || ($amHalfCount > 0 && $pmHalfCount > 0);
        $halfCount = $amHalfCount + $pmHalfCount;

        $effectivePrescribedMinutes = match (true) {
            $isFullDayLeave => $prescribedMinutes,
            $halfCount === 1 => intdiv($prescribedMinutes, 2),
            default => $prescribedMinutes,
        };

        return new self(
            paidLeaveDays: $paidLeaveDays,
            specialLeaveDays: $specialLeaveDays,
            paidLeaveMinutes: $paidLeaveMinutes,
            specialLeaveMinutes: $specialLeaveMinutes,
            effectivePrescribedMinutes: $effectivePrescribedMinutes,
            isFullDayLeave: $isFullDayLeave,
        );
    }
}
