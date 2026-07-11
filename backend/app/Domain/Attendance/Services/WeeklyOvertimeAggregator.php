<?php

namespace App\Domain\Attendance\Services;

use App\Models\AttendanceDay;
use App\Models\AttendanceWeeklyCalculation;
use App\Models\EmployeeShiftAssignment;
use Illuminate\Support\Carbon;

/**
 * 週単位の実働・残業を集計する(attendance_weekly_calculations Projection)。
 *
 * 注意 (.claude/skills/attendance-calc-review 参照):
 * - 1週40時間(労基法32条)は法定値であり会社設定ではないため定数として扱う。
 * - 日8時間超で既に統計済みの時間(attendance_daily_calculations.statutory_overtime_minutes)
 *   を除いた「日8時間以内の実働」だけを週単位で合計し、40時間を超えた分のみを
 *   weekly_statutory_overtime_minutes とする(日8時間判定との二重計上を避けるため)。
 * - 法定休日労働はこの週40時間の判定に含めない(法定休日労働は別枠の休日割増で扱う)。
 *   法定外休日(所定休日)の労働は通常の実働と同様にここへ含める。
 */
class WeeklyOvertimeAggregator
{
    private const WEEKLY_STATUTORY_LIMIT_MINUTES = 2400; // 労基法32条: 1週40時間

    public function recalculate(int $userId, string $workDate): AttendanceWeeklyCalculation
    {
        $date = Carbon::parse($workDate);
        $weekStartsOn = $this->resolveWeekStartsOn($userId, $date);

        $weekStart = $date->copy();
        while ($weekStart->isoWeekday() !== $weekStartsOn) {
            $weekStart->subDay();
        }
        $weekEnd = $weekStart->copy()->addDays(6);

        $days = AttendanceDay::query()
            ->where('user_id', $userId)
            ->whereDate('work_date', '>=', $weekStart->toDateString())
            ->whereDate('work_date', '<=', $weekEnd->toDateString())
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

        $weeklyStatutoryOvertimeMinutes = max(0, $withinDailyLimitMinutes - self::WEEKLY_STATUTORY_LIMIT_MINUTES);

        // updateOrCreate()の完全一致条件はdateキャストの保存形式差異で既存行を取りこぼす
        // ことがあるため、whereDate()で確実に既存行を探してから保存する。
        $weekly = AttendanceWeeklyCalculation::query()
            ->where('user_id', $userId)
            ->whereDate('week_start_date', $weekStart->toDateString())
            ->first() ?? new AttendanceWeeklyCalculation([
                'user_id' => $userId,
                'week_start_date' => $weekStart->toDateString(),
            ]);

        $weekly->fill([
            'week_end_date' => $weekEnd->toDateString(),
            'actual_work_minutes' => $actualWorkMinutes,
            'daily_statutory_overtime_minutes' => $dailyStatutoryOvertimeMinutes,
            'weekly_statutory_overtime_minutes' => $weeklyStatutoryOvertimeMinutes,
            'legal_holiday_work_minutes' => $legalHolidayWorkMinutes,
        ])->save();

        return $weekly;
    }

    private function resolveWeekStartsOn(int $userId, Carbon $date): int
    {
        $assignment = EmployeeShiftAssignment::query()
            ->where('user_id', $userId)
            ->whereDate('work_date', $date->toDateString())
            ->with('workStyle.calendar')
            ->first();

        return $assignment?->workStyle?->calendar?->week_starts_on ?? 1;
    }
}
