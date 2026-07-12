<?php

namespace App\Domain\Attendance\Services;

use App\Models\AttendanceDay;
use App\Models\EmployeeShiftAssignment;
use Illuminate\Support\Carbon;

/**
 * 週40時間(労基法32条)の参考集計。
 *
 * 注意 (.claude/skills/attendance-calc-review 参照):
 * - 週次勤怠は日次勤怠の編集ビューであり、月のように独立した集計単位ではない
 *   (CLAUDE.md「週次勤怠は日次勤怠の編集ビュー」)。そのため月次スナップショット
 *   (attendance_months.snapshot_json)には合算せず、Projectionとしても永続化しない。
 * - LegalHolidayRequirementChecker(UC-C005)と同じ考え方で、画面表示のたびに
 *   日次実績(attendance_daily_calculations)から都度再計算する読み取り専用の参考情報とする。
 * - 日8時間超で既に計上済みの時間(attendance_daily_calculations.statutory_overtime_minutes)
 *   を除いた「日8時間以内の実働」だけを週単位で合計し、40時間を超えた分のみを
 *   weekly_statutory_overtime_minutes とする(日8時間判定との二重計上を避けるため)。
 * - 法定休日労働はこの週40時間の判定に含めない(法定休日労働は別枠の休日割増で扱う)。
 */
class WeeklyOvertimeCalculator
{
    private const WEEKLY_STATUTORY_LIMIT_MINUTES = 2400; // 労基法32条: 1週40時間

    /**
     * @return list<array{week_start_date: string, week_end_date: string, actual_work_minutes: int, daily_statutory_overtime_minutes: int, weekly_statutory_overtime_minutes: int, legal_holiday_work_minutes: int}>
     */
    public function calculateForMonth(int $userId, string $yearMonth): array
    {
        $monthStart = Carbon::createFromFormat('Y-m', $yearMonth)->startOfMonth();
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
     * @return array{week_start_date: string, week_end_date: string, actual_work_minutes: int, daily_statutory_overtime_minutes: int, weekly_statutory_overtime_minutes: int, legal_holiday_work_minutes: int}
     */
    private function calculateWeek(int $userId, string $weekStartDate, string $weekEndDate): array
    {
        $days = AttendanceDay::query()
            ->where('user_id', $userId)
            ->whereDate('work_date', '>=', $weekStartDate)
            ->whereDate('work_date', '<=', $weekEndDate)
            ->with(['calculation', 'shiftAssignment'])
            ->get();

        $actualWorkMinutes = 0;
        $dailyStatutoryOvertimeMinutes = 0;
        $legalHolidayWorkMinutes = 0;
        $withinDailyLimitMinutes = 0;

        foreach ($days as $day) {
            $calculation = $day->calculation;
            if ($calculation === null) {
                continue;
            }

            $legalHolidayWorkMinutes += $calculation->legal_holiday_work_minutes;

            if ((bool) ($day->shiftAssignment?->is_legal_holiday)) {
                continue;
            }

            $actualWorkMinutes += $calculation->actual_work_minutes;
            $dailyStatutoryOvertimeMinutes += $calculation->statutory_overtime_minutes;
            $withinDailyLimitMinutes += $calculation->actual_work_minutes - $calculation->statutory_overtime_minutes;
        }

        return [
            'week_start_date' => $weekStartDate,
            'week_end_date' => $weekEndDate,
            'actual_work_minutes' => $actualWorkMinutes,
            'daily_statutory_overtime_minutes' => $dailyStatutoryOvertimeMinutes,
            'weekly_statutory_overtime_minutes' => max(0, $withinDailyLimitMinutes - self::WEEKLY_STATUTORY_LIMIT_MINUTES),
            'legal_holiday_work_minutes' => $legalHolidayWorkMinutes,
        ];
    }

    private function resolveWeekStartsOn(int $userId, Carbon $monthStart, Carbon $monthEnd): int
    {
        $assignment = EmployeeShiftAssignment::query()
            ->where('user_id', $userId)
            ->whereDate('work_date', '>=', $monthStart->toDateString())
            ->whereDate('work_date', '<=', $monthEnd->toDateString())
            ->orderBy('work_date')
            ->with('workStyle.calendar')
            ->first();

        return $assignment?->workStyle?->calendar?->week_starts_on ?? 1;
    }
}
