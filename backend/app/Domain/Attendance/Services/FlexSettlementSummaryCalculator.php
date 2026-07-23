<?php

namespace App\Domain\Attendance\Services;

use App\Models\AttendanceDay;
use App\Models\SystemSetting;
use App\Models\UserWorkStyleMonthlyAssignment;
use App\Models\WorkCalendarDay;
use App\Models\WorkStyle;
use Illuminate\Support\Carbon;

/**
 * フレックスタイム制(work_time_system=flex)の清算期間ダッシュボード(指示書 7.6節)。
 * LegalHolidayRequirementChecker/WeeklyOvertimeCalculatorと同じ考え方で、Projectionとして
 * 永続化せず表示のたびに都度計算する参考情報とする。
 *
 * 簡略化(指示書 25章参照):
 * - 清算期間の必要労働時間は「清算期間内の所定労働日数 × prescribed_daily_minutes」のみに
 *   対応する(指示書7.3節の複数の計算方式選択には未対応)。
 * - 対象の働き方は、その年月に割り当てられた働き方(user_work_style_monthly_assignments)、
 *   無ければシステムのデフォルト働き方の順で解決する(AttendanceCalculatorの
 *   resolveFallbackWorkStyleと同じ優先順位。ただし日別のシフト割当由来の働き方は見ない)。
 * - 働き方にカレンダーが設定されていない場合、月〜金を所定労働日とみなす(週40時間の
 *   法定労働時間総枠に基づく精密な清算期間残業計算は対象外。将来のフェーズで拡張する)。
 */
class FlexSettlementSummaryCalculator
{
    /**
     * @return array{settlement_period_start: string, settlement_period_end: string, required_minutes: int, actual_minutes: int, remaining_minutes: int, remaining_working_days: int, per_day_required_minutes: int, core_time_violation_days: int, late_night_work_minutes: int, legal_holiday_work_minutes: int}|null
     */
    public function calculateForMonth(string $userId, string $yearMonth): ?array
    {
        $workStyle = $this->resolveWorkStyle($userId, $yearMonth);

        if ($workStyle === null || $workStyle->work_time_system !== WorkStyle::WORK_TIME_SYSTEM_FLEX) {
            return null;
        }

        $referenceDate = Carbon::createFromFormat('Y-m', $yearMonth)->startOfMonth();
        [$periodStart, $periodEnd] = $workStyle->settlementPeriodBoundariesFor($referenceDate);

        $workingDates = $this->workingDatesWithinPeriod($workStyle, $periodStart, $periodEnd);
        $requiredMinutes = count($workingDates) * $workStyle->prescribed_daily_minutes;

        // 当日を含め、今日以降の所定労働日を残り勤務日数とする。
        $today = Carbon::today(SystemSetting::current()->default_timezone);
        $remainingWorkingDays = collect($workingDates)->filter(fn (Carbon $date) => $date->greaterThanOrEqualTo($today))->count();

        $days = AttendanceDay::query()
            ->where('user_id', $userId)
            ->whereDate('work_date', '>=', $periodStart->toDateString())
            ->whereDate('work_date', '<=', $periodEnd->toDateString())
            ->with('calculation')
            ->get();

        $actualMinutes = (int) $days->sum(fn (AttendanceDay $day) => $day->calculation?->work_minutes ?? 0);
        $lateNightWorkMinutes = (int) $days->sum(fn (AttendanceDay $day) => $day->calculation?->late_night_work_minutes ?? 0);
        $legalHolidayWorkMinutes = (int) $days->sum(fn (AttendanceDay $day) => $day->calculation?->legal_holiday_work_minutes ?? 0);
        $coreTimeViolationDays = $days->filter(fn (AttendanceDay $day) => $day->calculation?->core_time_violation === true)->count();

        $remainingMinutes = max(0, $requiredMinutes - $actualMinutes);

        return [
            'settlement_period_start' => $periodStart->toDateString(),
            'settlement_period_end' => $periodEnd->toDateString(),
            'required_minutes' => $requiredMinutes,
            'actual_minutes' => $actualMinutes,
            'remaining_minutes' => $remainingMinutes,
            'remaining_working_days' => $remainingWorkingDays,
            'per_day_required_minutes' => $remainingWorkingDays > 0 ? (int) ceil($remainingMinutes / $remainingWorkingDays) : 0,
            'core_time_violation_days' => $coreTimeViolationDays,
            'late_night_work_minutes' => $lateNightWorkMinutes,
            'legal_holiday_work_minutes' => $legalHolidayWorkMinutes,
        ];
    }

    /**
     * AttendanceCalculator::resolveFallbackWorkStyleと同じ優先順位(その月に割り当てられた
     * 働き方 → システムのデフォルト働き方)。清算期間ダッシュボードは月単位の働き方を前提とする
     * ため、日別のシフト割当由来の働き方は見ない。
     */
    private function resolveWorkStyle(string $userId, string $yearMonth): ?WorkStyle
    {
        $monthlyAssignment = UserWorkStyleMonthlyAssignment::query()
            ->where('user_id', $userId)
            ->where('year_month', $yearMonth)
            ->first();

        if ($monthlyAssignment !== null) {
            return $monthlyAssignment->workStyle;
        }

        return SystemSetting::current()->defaultWorkStyle;
    }

    /**
     * @return list<Carbon>
     */
    private function workingDatesWithinPeriod(WorkStyle $workStyle, Carbon $periodStart, Carbon $periodEnd): array
    {
        if ($workStyle->calendar_id !== null) {
            return WorkCalendarDay::query()
                ->where('calendar_id', $workStyle->calendar_id)
                ->whereDate('date', '>=', $periodStart->toDateString())
                ->whereDate('date', '<=', $periodEnd->toDateString())
                ->where('is_working_day', true)
                ->pluck('date')
                ->all();
        }

        $dates = [];
        $cursor = $periodStart->copy();
        while ($cursor->lessThanOrEqualTo($periodEnd)) {
            if (! $cursor->isWeekend()) {
                $dates[] = $cursor->copy();
            }
            $cursor->addDay();
        }

        return $dates;
    }
}
