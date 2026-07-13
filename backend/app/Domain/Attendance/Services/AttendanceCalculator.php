<?php

namespace App\Domain\Attendance\Services;

use App\Models\AttendanceDay;
use App\Models\SystemSetting;
use App\Models\UserWorkStyleMonthlyAssignment;
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
 * - 裁量労働制(work_time_system=discretionary)は、実労働時間ではなくみなし時間
 *   (work_styles.deemed_daily_minutes)を給与計算上の労働時間(payroll_work_minutes)とする。
 *   実労働時間(actual_work_minutes)は健康管理のための実績として別途保持し、両者を混同しない。
 *   深夜・法定休日・法定外休日の労働は、みなし時間ではなく実際の時刻から計算する。
 * - 管理監督者(work_time_system=manager_supervisor)は労働時間・休憩・休日の規定の適用が
 *   除外されるため、残業・休日の割増計算対象にはしない。ただし深夜割増は適用される。
 * - 法定休日「決めない方式」(work_styles.legal_holiday_rule=undetermined)は、
 *   `employee_shift_assignments.is_legal_holiday`を直接使わず、LegalHolidayResolverが
 *   指定または自動推定した日かどうかで判定する。
 * - 週40時間を含む正確な週次/月次の法定外残業判定は、月次確認画面の参考情報
 *   (WeeklyOvertimeCalculator)として別途都度計算する。
 * - その日の勤務予定(employee_shift_assignments)に働き方が紐づいていない場合でも
 *   勤怠は記録できる。その際の働き方は、(1) その月に割り当てられた働き方
 *   (user_work_style_monthly_assignments)、(2) それも無ければシステム全体設定の
 *   デフォルト働き方(system_settings.default_work_style_id)、の順にフォールバックする
 *   (docs/08-usecases-calendar-shift.md参照)。どちらも無ければ所定労働時間0扱いになる。
 */
class AttendanceCalculator
{
    private const LEGAL_DAILY_LIMIT_MINUTES = 480; // 労基法32条: 1日8時間

    private const LATE_NIGHT_START_HOUR = 22;

    private const LATE_NIGHT_END_HOUR = 5;

    public function __construct(private readonly LegalHolidayResolver $legalHolidayResolver) {}

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
        $workStyle = $shift?->workStyle ?? $this->resolveFallbackWorkStyle($day);
        $prescribedWorkMinutes = $workStyle?->prescribed_daily_minutes ?? 0;

        $plannedWorkMinutes = $shift?->plannedWorkMinutes() ?? 0;

        $isLegalHoliday = $shift !== null && $this->legalHolidayResolver->isLegalHoliday($shift);
        $isCompanyHoliday = (bool) ($shift?->is_company_holiday) && ! $isLegalHoliday;
        $isMonthlyVariable = $workStyle?->work_time_system === WorkStyle::WORK_TIME_SYSTEM_MONTHLY_VARIABLE;
        $isDiscretionary = $workStyle?->work_time_system === WorkStyle::WORK_TIME_SYSTEM_DISCRETIONARY;
        $isManagerSupervisor = $workStyle?->work_time_system === WorkStyle::WORK_TIME_SYSTEM_MANAGER_SUPERVISOR;

        // 裁量労働制の対象日(所定の稼働日かつ法定休日でない日)は、実労働時間にかかわらず
        // みなし時間を給与計算上の労働時間とする。法定休日の実労働は別途実績で計算するため対象外。
        $isScheduledWorkingDay = (bool) ($shift?->is_working_day ?? false);
        $deemedWorkMinutes = ($isDiscretionary && $isScheduledWorkingDay && ! $isLegalHoliday)
            ? ($workStyle->deemed_daily_minutes ?? 0)
            : 0;

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

        // 裁量労働制のみなし時間は8時間を超えた部分のみが法定時間外になる(所定内/所定外の
        // 区分はみなし制度上意味を持たないため0とする)。実労働時間ベースの計算を上書きする。
        if ($deemedWorkMinutes > 0) {
            $statutoryOvertimeMinutes = max(0, $deemedWorkMinutes - self::LEGAL_DAILY_LIMIT_MINUTES);
            $nonStatutoryOvertimeMinutes = 0;
        }

        // 管理監督者は労働時間・休憩・休日の規定の適用が除外されるため、残業・休日の
        // 割増計算対象にはしない(深夜割増は対象のためlate_night_minutesはここでは変更しない)。
        if ($isManagerSupervisor) {
            $statutoryOvertimeMinutes = 0;
            $nonStatutoryOvertimeMinutes = 0;
        }

        $payrollWorkMinutes = $deemedWorkMinutes > 0 ? $deemedWorkMinutes : $actualWorkMinutes;

        return [
            'planned_work_minutes' => $plannedWorkMinutes,
            'actual_work_minutes' => $actualWorkMinutes,
            'deemed_work_minutes' => $deemedWorkMinutes > 0 ? $deemedWorkMinutes : null,
            'payroll_work_minutes' => $payrollWorkMinutes,
            'prescribed_work_minutes' => $prescribedWorkMinutes,
            'non_statutory_overtime_minutes' => $nonStatutoryOvertimeMinutes,
            'statutory_overtime_minutes' => $statutoryOvertimeMinutes,
            'late_night_minutes' => $isLegalHoliday ? 0 : $lateNightMinutes,
            'legal_holiday_work_minutes' => ($isLegalHoliday && ! $isManagerSupervisor) ? $actualWorkMinutes : 0,
            'company_holiday_work_minutes' => ($isCompanyHoliday && ! $isManagerSupervisor) ? $actualWorkMinutes : 0,
            'legal_holiday_late_night_minutes' => $isLegalHoliday ? $lateNightMinutes : 0,
        ];
    }

    /**
     * その日の勤務予定に働き方が紐づいていない場合のフォールバック先を解決する。
     * その月に割り当てられた働き方 → システム全体設定のデフォルト働き方、の順で探す。
     */
    private function resolveFallbackWorkStyle(AttendanceDay $day): ?WorkStyle
    {
        $monthlyAssignment = UserWorkStyleMonthlyAssignment::query()
            ->where('user_id', $day->user_id)
            ->where('year_month', $day->work_date->format('Y-m'))
            ->first();

        if ($monthlyAssignment !== null) {
            return $monthlyAssignment->workStyle;
        }

        return SystemSetting::current()->defaultWorkStyle;
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
