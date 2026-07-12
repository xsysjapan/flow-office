<?php

namespace App\Domain\Attendance\Services;

use App\Models\EmployeeShiftAssignment;
use App\Models\LegalHolidayDesignation;
use App\Models\WorkStyle;
use Illuminate\Support\Carbon;

/**
 * 法定休日の判定。
 *
 * 「決める方式」(`legal_holiday_rule`が`weekly`/`four_weeks_four_days`)は、勤務予定に
 * 事前設定された`employee_shift_assignments.is_legal_holiday`をそのまま使う。
 *
 * 「決めない方式」(`undetermined`)は、どの日が法定休日かを事前に固定せず、週ごとに
 * 以下の優先順位で解決する。
 *
 * 1. 管理者・本人による指定(`legal_holiday_designations`)
 * 2. 自動推定: その週(勤務予定上)で休みとなっている最後の日
 *
 * 週内に休みの予定が1日も無い場合は法定休日を解決できない(null)。UC-C005の警告対象になる。
 */
class LegalHolidayResolver
{
    public function isLegalHoliday(EmployeeShiftAssignment $shift): bool
    {
        $workStyle = $shift->workStyle;

        if ($workStyle?->legal_holiday_rule !== WorkStyle::LEGAL_HOLIDAY_RULE_UNDETERMINED) {
            return (bool) $shift->is_legal_holiday;
        }

        $weekStartsOn = $workStyle->calendar?->week_starts_on ?? 1;
        $weekStart = $this->weekStartOf($shift->work_date->copy(), $weekStartsOn);

        $resolvedDate = $this->resolveDateForWeek($shift->user_id, $weekStart);

        return $resolvedDate !== null && $resolvedDate->isSameDay($shift->work_date);
    }

    /**
     * 指定した週(week_start_dateからの7日間)の法定休日の日付を解決する。
     * undetermined以外のルールでは呼び出し側が使わない想定(常にnullを返す)。
     */
    public function resolveDateForWeek(int $userId, Carbon $weekStart): ?Carbon
    {
        $designation = LegalHolidayDesignation::query()
            ->where('user_id', $userId)
            ->whereDate('week_start_date', $weekStart->toDateString())
            ->first();

        if ($designation !== null) {
            return $designation->designated_date->copy();
        }

        return $this->autoDetect($userId, $weekStart);
    }

    private function autoDetect(int $userId, Carbon $weekStart): ?Carbon
    {
        $weekEnd = $weekStart->copy()->addDays(6);

        $restDay = EmployeeShiftAssignment::query()
            ->where('user_id', $userId)
            ->where('is_working_day', false)
            ->whereDate('work_date', '>=', $weekStart->toDateString())
            ->whereDate('work_date', '<=', $weekEnd->toDateString())
            ->orderByDesc('work_date')
            ->first();

        return $restDay?->work_date?->copy();
    }

    public function weekStartOf(Carbon $date, int $weekStartsOn): Carbon
    {
        $weekStart = $date->copy();
        while ($weekStart->isoWeekday() !== $weekStartsOn) {
            $weekStart->subDay();
        }

        return $weekStart->startOfDay();
    }
}
