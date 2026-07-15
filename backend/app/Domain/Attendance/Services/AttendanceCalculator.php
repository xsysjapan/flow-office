<?php

namespace App\Domain\Attendance\Services;

use App\Models\AttendanceBreak;
use App\Models\AttendanceDay;
use App\Models\WorkStyle;
use Illuminate\Support\Carbon;

/**
 * 日次勤怠の労働時間・残業・深夜・休日労働を計算する。
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
 *   実労働時間(work_minutes)は健康管理のための実績として別途保持し、両者を混同しない。
 *   深夜・法定休日・法定外休日の労働は、みなし時間ではなく実際の時刻から計算する。
 * - 管理監督者(work_time_system=manager_supervisor)は労働時間・休憩・休日の規定の適用が
 *   除外されるため、残業・休日の割増計算対象にはしない。ただし深夜割増は適用される。
 * - 法定休日「決めない方式」(work_styles.legal_holiday_rule=undetermined)は、
 *   `employee_shift_assignments.is_legal_holiday`を直接使わず、LegalHolidayResolverが
 *   指定または自動推定した日かどうかで判定する。
 * - 週40時間を含む正確な週次/月次の法定外残業判定は、月次確認画面の参考情報
 *   (WeeklyOvertimeCalculator)として別途都度計算する。月60時間超の判定も同様に
 *   MonthlyOvertimeCalculatorが日次実績から都度計算する参考情報とし、ここでは計算しない。
 * - 深夜時間帯(22:00〜05:00)の労働は、区分別に`late_night_prescribed_work_minutes`(所定労働の
 *   深夜)・`late_night_statutory_within_overtime_minutes`(法定内残業の深夜)・
 *   `late_night_statutory_excess_overtime_minutes`(法定外残業の深夜)の3区分に分解する。
 *   残業は勤務時間の末尾から発生する前提(休憩を除いた労働時間を始業から時系列に辿り、所定
 *   労働→法定内残業→法定外残業の順に消化する)で、各区分の境界時刻を求め、区分ごとに深夜
 *   時間帯と重なる分を算出する(3区分の合計は`late_night_work_minutes`に一致する)。裁量労働制・
 *   管理監督者・フレックスタイム制など労働時間から法定外残業を判定しない勤務形態、および
 *   法定休日では算出しない(0とする。管理監督者・フレックスは所定内/所定外の区分自体が
 *   常に0になるため、深夜時間があれば全て`late_night_prescribed_work_minutes`に計上される)。
 * - フレックスタイム制(work_time_system=flex)は、日次の始業・終業時刻ではなく清算期間
 *   全体で労働時間を管理するため(指示書 7.1節)、日次の残業判定(statutory_within_overtime_minutes
 *   / statutory_excess_overtime_minutes)は行わず常に0とする(週40時間の法定労働時間総枠に基づく
 *   清算期間単位の過不足判定は、初期実装では簡略化しFlexSettlementSummaryCalculatorが
 *   清算期間の必要労働時間との単純な過不足のみを算出する。参考情報であり給与計算上の
 *   確定値ではない)。深夜・法定休日・法定外休日の労働は実際の時刻から通常通り計算する。
 *   コアタイム設定(work_styles.core_time_enabled)がある場合、実際の勤務がコアタイムを
 *   全てカバーしているかを`core_time_violation`として判定する(労働時間の過不足とは別枠の
 *   警告。指示書 7.4節)。
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

    public function __construct(
        private readonly LegalHolidayResolver $legalHolidayResolver,
        private readonly WorkStyleFallbackResolver $workStyleFallbackResolver,
    ) {}

    /**
     * @return array<string, int|bool|null>
     */
    public function calculate(AttendanceDay $day): array
    {
        $start = $day->actual_start_at;
        $end = $day->actual_end_at;
        $breaks = $day->breaks->filter(fn ($break) => $break->break_end_at !== null);

        $breakMinutes = $breaks->sum(fn ($break) => $break->break_start_at->diffInMinutes($break->break_end_at));

        $workMinutes = 0;
        $lateNightWorkMinutes = 0;
        if ($start !== null && $end !== null) {
            $workMinutes = max(0, $start->diffInMinutes($end) - $breakMinutes);

            $lateNightWorkMinutes = $this->lateNightOverlapMinutes($start, $end);
            foreach ($breaks as $break) {
                $lateNightWorkMinutes -= $this->lateNightOverlapMinutes($break->break_start_at, $break->break_end_at);
            }
            $lateNightWorkMinutes = max(0, $lateNightWorkMinutes);
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
        $isFlex = $workStyle?->work_time_system === WorkStyle::WORK_TIME_SYSTEM_FLEX;

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
        $statutoryWithinOvertimeMinutes = 0;
        $statutoryExcessOvertimeMinutes = 0;
        if (! $isLegalHoliday) {
            $overtimeBaselineMinutes = $isCompanyHoliday ? 0 : ($isMonthlyVariable ? $plannedWorkMinutes : $prescribedWorkMinutes);
            $statutoryExcessOvertimeMinutes = max(0, $workMinutes - $legalDailyLimitMinutes);
            $withinLegalMinutes = min($workMinutes, $legalDailyLimitMinutes);
            $statutoryWithinOvertimeMinutes = max(0, $withinLegalMinutes - $overtimeBaselineMinutes);
        }

        // 裁量労働制のみなし時間は8時間を超えた部分のみが法定時間外になる(所定内/所定外の
        // 区分はみなし制度上意味を持たないため0とする)。実労働時間ベースの計算を上書きする。
        if ($deemedWorkMinutes > 0) {
            $statutoryExcessOvertimeMinutes = max(0, $deemedWorkMinutes - self::LEGAL_DAILY_LIMIT_MINUTES);
            $statutoryWithinOvertimeMinutes = 0;
        }

        // 管理監督者は労働時間・休憩・休日の規定の適用が除外されるため、残業・休日の
        // 割増計算対象にはしない(深夜割増は対象のためlate_night_work_minutesはここでは変更しない)。
        if ($isManagerSupervisor) {
            $statutoryExcessOvertimeMinutes = 0;
            $statutoryWithinOvertimeMinutes = 0;
        }

        // フレックスタイム制は清算期間全体で労働時間を管理するため、日次の残業判定は行わない
        // (清算期間単位の過不足はFlexSettlementSummaryCalculatorが別途算出する)。
        if ($isFlex) {
            $statutoryExcessOvertimeMinutes = 0;
            $statutoryWithinOvertimeMinutes = 0;
        }

        $payrollWorkMinutes = $deemedWorkMinutes > 0 ? $deemedWorkMinutes : $workMinutes;

        $coreTimeViolation = $this->isCoreTimeViolated($isFlex, $workStyle, $day->work_date, $start, $end);

        // 残業が実際の勤務時刻(労働時間)から判定されている場合のみ、深夜時間帯を所定労働/
        // 法定内残業/法定外残業の3区分に分解する。裁量労働制のみなし時間ベースの判定、
        // 法定休日では算出しない(0のまま)。
        $lateNightPrescribedWorkMinutes = 0;
        $lateNightStatutoryWithinOvertimeMinutes = 0;
        $lateNightStatutoryExcessOvertimeMinutes = 0;
        if ($deemedWorkMinutes === 0 && ! $isLegalHoliday && $start !== null && $end !== null && $lateNightWorkMinutes > 0) {
            $regularWorkMinutes = max(0, $workMinutes - $statutoryWithinOvertimeMinutes - $statutoryExcessOvertimeMinutes);
            $nonStatutoryOvertimeBoundary = $this->findOvertimeBoundary($start, $end, $breaks, $regularWorkMinutes);
            $statutoryOvertimeBoundary = $this->findOvertimeBoundary($start, $end, $breaks, $regularWorkMinutes + $statutoryWithinOvertimeMinutes);

            $lateNightPrescribedWorkMinutes = $this->lateNightOverlapMinutesInRange($start, $nonStatutoryOvertimeBoundary ?? $end, $breaks);

            if ($nonStatutoryOvertimeBoundary !== null) {
                $lateNightStatutoryWithinOvertimeMinutes = $this->lateNightOverlapMinutesInRange(
                    $nonStatutoryOvertimeBoundary,
                    $statutoryOvertimeBoundary ?? $end,
                    $breaks,
                );
            }

            if ($statutoryOvertimeBoundary !== null) {
                $lateNightStatutoryExcessOvertimeMinutes = $this->lateNightOverlapMinutesInRange($statutoryOvertimeBoundary, $end, $breaks);
            }
        }

        return [
            'planned_work_minutes' => $plannedWorkMinutes,
            'work_minutes' => $workMinutes,
            'deemed_work_minutes' => $deemedWorkMinutes > 0 ? $deemedWorkMinutes : null,
            'payroll_work_minutes' => $payrollWorkMinutes,
            'prescribed_work_minutes' => $prescribedWorkMinutes,
            'statutory_within_overtime_minutes' => $statutoryWithinOvertimeMinutes,
            'statutory_excess_overtime_minutes' => $statutoryExcessOvertimeMinutes,
            'late_night_work_minutes' => $isLegalHoliday ? 0 : $lateNightWorkMinutes,
            'late_night_prescribed_work_minutes' => $lateNightPrescribedWorkMinutes,
            'late_night_statutory_within_overtime_minutes' => $lateNightStatutoryWithinOvertimeMinutes,
            'late_night_statutory_excess_overtime_minutes' => $lateNightStatutoryExcessOvertimeMinutes,
            'legal_holiday_work_minutes' => ($isLegalHoliday && ! $isManagerSupervisor) ? $workMinutes : 0,
            'prescribed_holiday_work_minutes' => ($isCompanyHoliday && ! $isManagerSupervisor) ? $workMinutes : 0,
            'late_night_legal_holiday_work_minutes' => $isLegalHoliday ? $lateNightWorkMinutes : 0,
            'core_time_violation' => $coreTimeViolation,
        ];
    }

    /**
     * コアタイム違反判定(指示書 7.4節): フレックスタイム制でコアタイムが有効な場合、
     * その日の勤務(actual_start_at〜actual_end_at)がコアタイムを全てカバーしているかを
     * 判定する。労働時間の不足とは別枠の警告であり、当日出退勤の実績が無い日は判定しない
     * (実績が無いこと自体は別の未出勤警告の対象であり、ここでは対象外とする)。
     */
    private function isCoreTimeViolated(bool $isFlex, ?WorkStyle $workStyle, Carbon $workDate, ?Carbon $start, ?Carbon $end): bool
    {
        if (! $isFlex || ! $workStyle?->core_time_enabled || $start === null || $end === null) {
            return false;
        }
        if ($workStyle->core_time_start === null || $workStyle->core_time_end === null) {
            return false;
        }

        $coreStart = $workDate->copy()->setTimeFromTimeString($workStyle->core_time_start);
        $coreEnd = $workDate->copy()->setTimeFromTimeString($workStyle->core_time_end);

        return ! ($start->lessThanOrEqualTo($coreStart) && $end->greaterThanOrEqualTo($coreEnd));
    }

    /**
     * その日の勤務予定に働き方が紐づいていない場合のフォールバック先を解決する。
     * その月に割り当てられた働き方 → システム全体設定のデフォルト働き方、の順で探す。
     */
    private function resolveFallbackWorkStyle(AttendanceDay $day): ?WorkStyle
    {
        return $this->workStyleFallbackResolver->resolveForUser($day->user_id, $day->work_date->copy());
    }

    /**
    * 労働時間(休憩を除く)が$thresholdMinutesに達する時刻を求める。残業は勤務時間の末尾から
     * 発生する前提(休憩を除いた勤務区間を時系列に辿り、累計が閾値に達した時点)で境界を返す。
     * 閾値に達しないまま退勤時刻を迎えた場合(=残業が無い場合)はnullを返す。
     *
     * @param  iterable<int, AttendanceBreak>  $breaks
     */
    private function findOvertimeBoundary(Carbon $start, Carbon $end, iterable $breaks, int $thresholdMinutes): ?Carbon
    {
        $sortedBreaks = collect($breaks)->sortBy(fn ($break) => $break->break_start_at)->values();

        $cursor = $start->copy();
        $accumulated = 0;

        foreach ($sortedBreaks as $break) {
            if ($break->break_start_at->greaterThan($cursor)) {
                $segmentMinutes = $cursor->diffInMinutes($break->break_start_at);
                if ($accumulated + $segmentMinutes >= $thresholdMinutes) {
                    return $cursor->copy()->addMinutes($thresholdMinutes - $accumulated);
                }
                $accumulated += $segmentMinutes;
            }
            if ($break->break_end_at->greaterThan($cursor)) {
                $cursor = $break->break_end_at->copy();
            }
        }

        if ($end->greaterThan($cursor)) {
            $segmentMinutes = $cursor->diffInMinutes($end);
            if ($accumulated + $segmentMinutes >= $thresholdMinutes) {
                return $cursor->copy()->addMinutes($thresholdMinutes - $accumulated);
            }
        }

        return null;
    }

    /**
     * [$rangeStart, $rangeEnd)の区間のうち深夜時間帯(22:00〜05:00)と重なる分を、休憩による
     * 重複を除いて算出する。
     *
     * @param  iterable<int, AttendanceBreak>  $breaks
     */
    private function lateNightOverlapMinutesInRange(Carbon $rangeStart, Carbon $rangeEnd, iterable $breaks): int
    {
        $minutes = $this->lateNightOverlapMinutes($rangeStart, $rangeEnd);

        foreach ($breaks as $break) {
            $breakStart = $break->break_start_at->greaterThan($rangeStart) ? $break->break_start_at : $rangeStart;
            $breakEnd = $break->break_end_at->lessThan($rangeEnd) ? $break->break_end_at : $rangeEnd;
            if ($breakEnd->greaterThan($breakStart)) {
                $minutes -= $this->lateNightOverlapMinutes($breakStart, $breakEnd);
            }
        }

        return max(0, $minutes);
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
