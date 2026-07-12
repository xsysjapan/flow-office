<?php

namespace App\Domain\Attendance\Services;

use App\Models\AttendanceDay;
use App\Models\WorkStyle;
use Illuminate\Support\Carbon;

/**
 * 日次勤怠の実働・残業・深夜・休日労働を計算する。
 *
 * 注意 (.claude/skills/attendance-calc-review 参照):
 * - 1日8時間の法定労働時間・22:00〜05:00の深夜時間は労働基準法で定められた値であり、
 *   会社設定ではないためここでは定数として扱う。
 * - 所定労働時間はwork_stylesマスタから取得し、ハードコードしない。
 * - 1か月単位変形労働時間制(work_time_system=monthly_variable)では、あらかじめ8時間を
 *   超える所定労働時間を設定した日はその時間を超えた部分のみが日8時間超の法定時間外になる
 *   (docs/08-usecases-calendar-shift.md「1か月単位変形労働時間制」参照)。
 * - 週40時間を含む正確な週次/月次の法定外残業判定は、月次確認画面の参考情報
 *   (WeeklyOvertimeCalculator)として別途都度計算する。
 */
class AttendanceCalculator
{
    private const LEGAL_DAILY_LIMIT_MINUTES = 480; // 労基法32条: 1日8時間

    private const LATE_NIGHT_START_HOUR = 22;

    private const LATE_NIGHT_END_HOUR = 5;

    /**
     * @return array<string, int>
     */
    public function calculate(AttendanceDay $day): array
    {
        $start = $day->actual_start_at;
        $end = $day->actual_end_at;
        $breaks = $day->breaks->filter(fn ($break) => $break->break_end_at !== null);

        $breakMinutes = $breaks->sum(fn ($break) => $break->break_start_at->diffInMinutes($break->break_end_at));

        $actualWorkMinutes = 0;
        $lateNightMinutes = 0;
        if ($start !== null && $end !== null) {
            $actualWorkMinutes = max(0, $start->diffInMinutes($end) - $breakMinutes);

            $lateNightMinutes = $this->lateNightOverlapMinutes($start, $end);
            foreach ($breaks as $break) {
                $lateNightMinutes -= $this->lateNightOverlapMinutes($break->break_start_at, $break->break_end_at);
            }
            $lateNightMinutes = max(0, $lateNightMinutes);
        }

        $shift = $day->shiftAssignment;
        $workStyle = $shift?->workStyle;
        $prescribedWorkMinutes = $workStyle?->prescribed_daily_minutes ?? 0;

        $plannedWorkMinutes = $shift?->plannedWorkMinutes() ?? 0;

        $isLegalHoliday = (bool) ($shift?->is_legal_holiday);
        $isCompanyHoliday = (bool) ($shift?->is_company_holiday) && ! $isLegalHoliday;
        $isMonthlyVariable = $workStyle?->work_time_system === WorkStyle::WORK_TIME_SYSTEM_MONTHLY_VARIABLE;

        // あらかじめ8時間を超える所定労働時間を設定した日(1か月単位変形労働時間制)は、
        // その時間を超えた部分のみが日8時間超の法定時間外になる。
        $legalDailyLimitMinutes = ($isMonthlyVariable && $plannedWorkMinutes > self::LEGAL_DAILY_LIMIT_MINUTES)
            ? $plannedWorkMinutes
            : self::LEGAL_DAILY_LIMIT_MINUTES;

        // 法定休日の労働は日8時間の判定に乗せず、全て法定休日労働として扱う。
        // 法定外休日(所定休日)の労働は、それだけを理由に休日割増は付けないが、1日8時間・
        // 週40時間の判定からは除外しない(所定休日での「所定」は0分として扱う)。
        $nonStatutoryOvertimeMinutes = 0;
        $statutoryOvertimeMinutes = 0;
        if (! $isLegalHoliday) {
            $overtimeBaselineMinutes = $isCompanyHoliday ? 0 : ($isMonthlyVariable ? $plannedWorkMinutes : $prescribedWorkMinutes);
            $statutoryOvertimeMinutes = max(0, $actualWorkMinutes - $legalDailyLimitMinutes);
            $withinLegalMinutes = min($actualWorkMinutes, $legalDailyLimitMinutes);
            $nonStatutoryOvertimeMinutes = max(0, $withinLegalMinutes - $overtimeBaselineMinutes);
        }

        return [
            'planned_work_minutes' => $plannedWorkMinutes,
            'actual_work_minutes' => $actualWorkMinutes,
            'prescribed_work_minutes' => $prescribedWorkMinutes,
            'non_statutory_overtime_minutes' => $nonStatutoryOvertimeMinutes,
            'statutory_overtime_minutes' => $statutoryOvertimeMinutes,
            'late_night_minutes' => $isLegalHoliday ? 0 : $lateNightMinutes,
            'legal_holiday_work_minutes' => $isLegalHoliday ? $actualWorkMinutes : 0,
            'company_holiday_work_minutes' => $isCompanyHoliday ? $actualWorkMinutes : 0,
            'legal_holiday_late_night_minutes' => $isLegalHoliday ? $lateNightMinutes : 0,
        ];
    }

    private function lateNightOverlapMinutes(Carbon $start, Carbon $end): int
    {
        if ($end->lessThanOrEqualTo($start)) {
            return 0;
        }

        $total = 0;
        $cursor = $start->copy()->startOfDay();

        while ($cursor->lessThan($end)) {
            $windowStart = $cursor->copy()->setTime(self::LATE_NIGHT_START_HOUR, 0);
            $windowEnd = $cursor->copy()->addDay()->setTime(self::LATE_NIGHT_END_HOUR, 0);
            $total += $this->overlapMinutes($start, $end, $windowStart, $windowEnd);
            $cursor->addDay();
        }

        return $total;
    }

    private function overlapMinutes(Carbon $aStart, Carbon $aEnd, Carbon $bStart, Carbon $bEnd): int
    {
        $overlapStart = $aStart->greaterThan($bStart) ? $aStart : $bStart;
        $overlapEnd = $aEnd->lessThan($bEnd) ? $aEnd : $bEnd;

        return $overlapEnd->greaterThan($overlapStart) ? $overlapStart->diffInMinutes($overlapEnd) : 0;
    }
}
