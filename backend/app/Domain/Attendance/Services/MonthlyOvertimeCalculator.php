<?php

namespace App\Domain\Attendance\Services;

use App\Models\AttendanceDailyCalculation;
use App\Models\AttendanceDay;

/**
 * 月60時間超残業(労基法37条、中小企業も2023年4月以降適用)の集計。
 *
 * 注意 (.claude/skills/attendance-calc-review 参照):
 * - `attendance_daily_calculations.statutory_overtime_minutes`(法定外残業。法定休日労働は
 *   含まない)を対象月の月初から都度合算し、60時間を超えた分だけを
 *   `statutory_overtime_over_60h_minutes` とする。
 * - `calculateForDate`(日次画面用、月初からその日までの累計)はProjectionとしては永続化せず、
 *   `attendance_months.snapshot_json`にも合算しない、WeeklyOvertimeCalculatorと同じ表示専用の
 *   参考情報として扱う(月をまたぐ日次編集の反映漏れ防止のため)。
 * - `calculateCategoryTotals`(月次確認画面・月次提出スナップショット用、月全体の合計)は、
 *   週40時間判定(週単位の再集計で日次計上済み分と重複しうる)とは異なり、月全体の
 *   `statutory_overtime_minutes`を「60時間以内」「60時間超」に単純に按分するだけで
 *   二重計上が生じないため、`attendance_months.snapshot_json`に含めてよい。
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

    /**
     * 月次確認画面・月次提出スナップショット向けの、対象月全体の集計(9区分の合計)。
     *
     * @return array{actual_work_minutes: int, payroll_work_minutes: int, prescribed_work_minutes: int, non_statutory_overtime_minutes: int, statutory_overtime_minutes: int, statutory_overtime_within_60h_minutes: int, statutory_overtime_over_60h_minutes: int, late_night_minutes: int, regular_work_late_night_minutes: int, non_statutory_overtime_late_night_minutes: int, statutory_overtime_late_night_minutes: int, legal_holiday_work_minutes: int, company_holiday_work_minutes: int, legal_holiday_late_night_minutes: int}
     */
    public function calculateCategoryTotals(int $userId, string $yearMonth): array
    {
        $dayIds = AttendanceDay::query()
            ->where('user_id', $userId)
            ->where('work_date', 'like', "{$yearMonth}%")
            ->pluck('id');

        $calculations = AttendanceDailyCalculation::query()->whereIn('attendance_day_id', $dayIds)->get();

        $statutoryOvertimeTotal = (int) $calculations->sum('statutory_overtime_minutes');

        return [
            'actual_work_minutes' => (int) $calculations->sum('actual_work_minutes'),
            'payroll_work_minutes' => (int) $calculations->sum('payroll_work_minutes'),
            'prescribed_work_minutes' => (int) $calculations->sum('prescribed_work_minutes'),
            'non_statutory_overtime_minutes' => (int) $calculations->sum('non_statutory_overtime_minutes'),
            'statutory_overtime_minutes' => $statutoryOvertimeTotal,
            'statutory_overtime_within_60h_minutes' => min($statutoryOvertimeTotal, self::MONTHLY_STATUTORY_LIMIT_MINUTES),
            'statutory_overtime_over_60h_minutes' => max(0, $statutoryOvertimeTotal - self::MONTHLY_STATUTORY_LIMIT_MINUTES),
            'late_night_minutes' => (int) $calculations->sum('late_night_minutes'),
            'regular_work_late_night_minutes' => (int) $calculations->sum('regular_work_late_night_minutes'),
            'non_statutory_overtime_late_night_minutes' => (int) $calculations->sum('non_statutory_overtime_late_night_minutes'),
            'statutory_overtime_late_night_minutes' => (int) $calculations->sum('statutory_overtime_late_night_minutes'),
            'legal_holiday_work_minutes' => (int) $calculations->sum('legal_holiday_work_minutes'),
            'company_holiday_work_minutes' => (int) $calculations->sum('company_holiday_work_minutes'),
            'legal_holiday_late_night_minutes' => (int) $calculations->sum('legal_holiday_late_night_minutes'),
        ];
    }
}
