<?php

namespace App\Domain\Attendance\Services;

use App\Models\AttendanceDay;

/**
 * 月60時間超残業(労基法37条、中小企業も2023年4月以降適用)の参考集計。
 *
 * 注意 (.claude/skills/attendance-calc-review 参照):
 * - WeeklyOvertimeCalculatorと同じ考え方で、`attendance_daily_calculations.statutory_overtime_minutes`
 *   (法定外残業。法定休日労働は含まない)を対象月の月初から都度合算し、60時間を超えた分だけを
 *   `statutory_overtime_over_60h_minutes` とする表示専用の参考情報とする。Projectionとしては
 *   永続化せず、`attendance_months.snapshot_json`にも合算しない(二重計上防止・月をまたぐ
 *   日次編集の反映漏れ防止のため)。
 * - 法定休日労働はstatutory_overtime_minutes自体に含まれないため、この60時間判定からも
 *   自然に除外される(AttendanceCalculatorが法定休日の日はstatutory_overtime_minutesを0にする)。
 */
class MonthlyOvertimeCalculator
{
    private const MONTHLY_STATUTORY_LIMIT_MINUTES = 3600; // 労基法37条: 月60時間

    /**
     * @return array{cumulative_statutory_overtime_minutes: int, statutory_overtime_within_60h_minutes: int, statutory_overtime_over_60h_minutes: int}
     */
    public function calculateForDate(int $userId, string $workDate): array
    {
        $yearMonth = substr($workDate, 0, 7);

        $days = AttendanceDay::query()
            ->where('user_id', $userId)
            ->whereDate('work_date', '>=', "{$yearMonth}-01")
            ->whereDate('work_date', '<=', $workDate)
            ->with('calculation')
            ->orderBy('work_date')
            ->get();

        $cumulativeBeforeToday = 0;
        $todayMinutes = 0;

        foreach ($days as $day) {
            $minutes = $day->calculation->statutory_overtime_minutes ?? 0;
            if ($day->work_date->toDateString() === $workDate) {
                $todayMinutes = $minutes;

                break;
            }
            $cumulativeBeforeToday += $minutes;
        }

        $remainingWithin60h = max(0, self::MONTHLY_STATUTORY_LIMIT_MINUTES - $cumulativeBeforeToday);
        $withinMinutes = min($todayMinutes, $remainingWithin60h);
        $overMinutes = $todayMinutes - $withinMinutes;

        return [
            'cumulative_statutory_overtime_minutes' => $cumulativeBeforeToday + $todayMinutes,
            'statutory_overtime_within_60h_minutes' => $withinMinutes,
            'statutory_overtime_over_60h_minutes' => $overMinutes,
        ];
    }
}
