<?php

namespace App\Domain\Attendance\Services;

use App\Models\SystemSetting;
use App\Models\UserWorkStyleMonthlyAssignment;
use App\Models\WorkStyle;
use Illuminate\Support\Carbon;

/**
 * その勤務日に勤務予定(employee_shift_assignments)の紐づく働き方が無い場合の
 * フォールバック先を解決する。その月に割り当てられた働き方
 * (user_work_style_monthly_assignments) → システム全体設定のデフォルト働き方
 * (system_settings.default_work_style_id) の順に探す(docs/08-usecases-calendar-shift.md参照)。
 * AttendanceCalculatorと日次入力の初期値解決(AttendanceDayDefaultsResolver)の両方で使う。
 */
class WorkStyleFallbackResolver
{
    public function resolveForUser(int $userId, Carbon $workDate): ?WorkStyle
    {
        $monthlyAssignment = UserWorkStyleMonthlyAssignment::query()
            ->where('user_id', $userId)
            ->where('year_month', $workDate->format('Y-m'))
            ->first();

        if ($monthlyAssignment !== null) {
            return $monthlyAssignment->workStyle;
        }

        return SystemSetting::current()->defaultWorkStyle;
    }
}
