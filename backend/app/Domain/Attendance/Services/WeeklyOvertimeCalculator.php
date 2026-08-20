<?php

namespace App\Domain\Attendance\Services;

use App\Models\AttendanceDay;
use App\Models\AttendanceWeeklyOvertimeAllocation;
use App\Models\EmployeeCalendarEntry;
use App\Models\WorkStyle;
use Illuminate\Support\Carbon;

/**
 * 週40時間(労基法32条)の参考集計。
 *
 * 注意 (.claude/skills/attendance-calc-review 参照):
 * - 週次勤怠は日次勤怠の編集ビューであり、月のように独立した集計単位ではない
 *   (CLAUDE.md「週次勤怠は日次勤怠の編集ビュー」)。そのため本クラスが返す週ごとの内訳自体は
 *   月次スナップショット(attendance_months.snapshot_json)には含めず、Projectionとしても
 *   永続化しない。ただし月内の全週を合算した月間の週40時間超残業総量は、月60時間超と同様に
 *   確定値として扱ってよいため、`MonthlyOvertimeCalculator::calculateCategoryTotals()`が
 *   本クラスの`calculateForMonth()`を呼んで合算し、`weekly_statutory_excess_overtime_minutes`
 *   として月次確認画面・月次提出スナップショットに含める。
 * - LegalHolidayRequirementChecker(UC-C005)と同じ考え方で、画面表示のたびに
 *   日次実績(attendance_daily_calculations)から都度再計算する読み取り専用の参考情報とする。
 * - 日8時間超で既に計上済みの時間(attendance_daily_calculations.statutory_excess_overtime_minutes)
 *   を除いた「日8時間以内の労働時間」だけを週単位で合計し、40時間を超えた分のみを
 *   weekly_statutory_excess_overtime_minutes とする(日8時間判定との二重計上を避けるため)。
 * - 法定休日労働はこの週40時間の判定に含めない(法定休日労働は別枠の休日割増で扱う)。
 * - 1か月単位変形労働時間制(work_time_system=monthly_variable)で、あらかじめ40時間を
 *   超える所定労働時間を設定した週は、その時間を超えた部分のみが週40時間超の法定時間外になる。
 * - 法定休日「決めない方式」(work_styles.legal_holiday_rule=undetermined)は、
 *   `employee_calendar_entries.is_legal_holiday`を直接使わず、LegalHolidayResolverが
 *   指定または自動推定した日かどうかで判定する(AttendanceCalculatorと同じ判定基準)。
 */
class WeeklyOvertimeCalculator
{
    private const WEEKLY_STATUTORY_LIMIT_MINUTES = 2400; // 労基法32条: 1週40時間

    public function __construct(
        private readonly LegalHolidayResolver $legalHolidayResolver,
        private readonly EffectiveScheduleResolver $effectiveScheduleResolver,
    ) {}

    /**
     * @return list<array{week_start_date: string, week_end_date: string, work_minutes: int, daily_statutory_excess_overtime_minutes: int, weekly_statutory_excess_overtime_minutes: int, legal_holiday_work_minutes: int}>
     */
    public function calculateForMonth(string $userId, string $yearMonth): array
    {
        // 'Y-m'のみだと日付部分が実行時点の日で補完され、対象月の日数を超える日(29〜31日)に
        // 実行すると翌月へ繰り上がってしまうため、日付を明示して安全にパースする。
        $monthStart = Carbon::createFromFormat('Y-m-d', "{$yearMonth}-01")->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();
        $weekStartsOn = $this->resolveWeekStartsOn($userId, $monthStart, $monthEnd);

        $windowStart = $monthStart->copy();
        while ($windowStart->isoWeekday() !== $weekStartsOn) {
            $windowStart->subDay();
        }

        $weeks = [];
        $cursor = $windowStart->copy();
        while ($cursor->lte($monthEnd)) {
            $weeks[] = $this->calculateWeek($userId, $cursor->toDateString(), $cursor->copy()->addDays(6)->toDateString());
            $cursor->addDays(7);
        }

        return $weeks;
    }

    /**
     * @return array{week_start_date: string, week_end_date: string, work_minutes: int, daily_statutory_excess_overtime_minutes: int, weekly_statutory_excess_overtime_minutes: int, legal_holiday_work_minutes: int}
     */
    public function calculateWeek(string $userId, string $weekStartDate, string $weekEndDate): array
    {
        $days = AttendanceDay::query()
            ->where('user_id', $userId)
            ->whereDate('work_date', '>=', $weekStartDate)
            ->whereDate('work_date', '<=', $weekEndDate)
            ->with(['calculation', 'calendarEntry.workStyle.calendar'])
            ->get();

        $workMinutes = 0;
        $dailyStatutoryOvertimeMinutes = 0;
        $legalHolidayWorkMinutes = 0;
        $withinDailyLimitMinutes = 0;
        $plannedMinutesForMonthlyVariable = 0;
        $allocations = AttendanceWeeklyOvertimeAllocation::query()
            ->whereIn('attendance_day_id', $days->pluck('id'))
            ->get();
        $allocationsByDay = $allocations->keyBy('attendance_day_id');

        foreach ($days as $day) {
            $calculation = $day->calculation;
            if ($calculation === null) {
                continue;
            }

            $legalHolidayWorkMinutes += $calculation->legal_holiday_work_minutes;

            $schedule = $this->effectiveScheduleResolver->resolve(
                $day->user_id,
                $day->work_date->copy(),
                $day->calendarEntry,
            );

            if ($schedule !== null && $this->legalHolidayResolver->isLegalHoliday($schedule)) {
                continue;
            }

            $allocation = $allocationsByDay->get($day->id);
            $weeklyAllocatedMinutes = (int) (($allocation?->prescribed_minutes ?? 0) + ($allocation?->non_prescribed_minutes ?? 0));
            // 互換列には振分済み週40時間超も含めるため、日8時間超の元値へ戻して判定する。
            $dailyExcessMinutes = max(0, $calculation->statutory_excess_overtime_minutes - $weeklyAllocatedMinutes);
            $workMinutes += $calculation->work_minutes;
            $dailyStatutoryOvertimeMinutes += $dailyExcessMinutes;
            $withinDailyLimitMinutes += $calculation->work_minutes - $dailyExcessMinutes;

            if ($schedule?->workStyle?->work_time_system === WorkStyle::WORK_TIME_SYSTEM_MONTHLY_VARIABLE) {
                $plannedMinutesForMonthlyVariable += $schedule->plannedWorkMinutes();
            }
        }

        $weeklyLimitMinutes = max(self::WEEKLY_STATUTORY_LIMIT_MINUTES, $plannedMinutesForMonthlyVariable);
        $weeklyExcessMinutes = max(0, $withinDailyLimitMinutes - $weeklyLimitMinutes);
        $allocatedMinutes = (int) $allocations->sum(fn ($allocation) => $allocation->prescribed_minutes + $allocation->non_prescribed_minutes);

        return [
            'week_start_date' => $weekStartDate,
            'week_end_date' => $weekEndDate,
            'work_minutes' => $workMinutes,
            'daily_statutory_excess_overtime_minutes' => $dailyStatutoryOvertimeMinutes,
            'weekly_statutory_excess_overtime_minutes' => $weeklyExcessMinutes,
            'allocated_weekly_statutory_excess_overtime_minutes' => $allocatedMinutes,
            'unallocated_weekly_statutory_excess_overtime_minutes' => max(0, $weeklyExcessMinutes - $allocatedMinutes),
            'legal_holiday_work_minutes' => $legalHolidayWorkMinutes,
        ];
    }

    private function resolveWeekStartsOn(string $userId, Carbon $monthStart, Carbon $monthEnd): int
    {
        $assignment = EmployeeCalendarEntry::query()
            ->where('user_id', $userId)
            ->whereDate('work_date', '>=', $monthStart->toDateString())
            ->whereDate('work_date', '<=', $monthEnd->toDateString())
            ->orderBy('work_date')
            ->with('workStyle.calendar')
            ->first();

        return $assignment?->workStyle?->calendar?->week_starts_on ?? 1;
    }
}
