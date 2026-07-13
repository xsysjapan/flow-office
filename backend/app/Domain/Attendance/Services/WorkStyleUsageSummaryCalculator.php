<?php

namespace App\Domain\Attendance\Services;

use App\Models\EmployeeShiftAssignment;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\UserWorkStyleMonthlyAssignment;
use App\Models\WorkStyle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 働き方一覧画面(指示書 16.1節)の管理者向け集計列を都度計算する。
 * LegalHolidayRequirementChecker/WeeklyOvertimeCalculatorと同じ考え方で、専用の
 * Projectionは持たず表示のたびに計算する参考情報とする。
 *
 * 指示書16.1節・16.2節が求める「有効期間」「状態(下書き/有効/将来有効/無効/廃止)」は、
 * work_stylesにライフサイクル管理の概念(is_active・バージョニング等)がまだ無いため
 * 未対応。ここでは既存データから機械的に算出できる列のみを対象とする。
 */
class WorkStyleUsageSummaryCalculator
{
    /**
     * @param  Collection<int, WorkStyle>  $workStyles
     * @return array<int, array{applied_employee_count: int, active_shift_pattern_count: int|null, configuration_warnings: list<string>}>
     */
    public function calculateFor(Collection $workStyles): array
    {
        $currentYearMonth = Carbon::now(SystemSetting::current()->default_timezone)->format('Y-m');

        $assignmentCounts = UserWorkStyleMonthlyAssignment::query()
            ->where('year_month', $currentYearMonth)
            ->selectRaw('work_style_id, count(*) as aggregate')
            ->groupBy('work_style_id')
            ->pluck('aggregate', 'work_style_id');

        $shiftPatternCounts = EmployeeShiftAssignment::query()
            ->whereNotNull('shift_pattern_id')
            ->selectRaw('work_style_id, count(distinct shift_pattern_id) as aggregate')
            ->groupBy('work_style_id')
            ->pluck('aggregate', 'work_style_id');

        $usedWorkStyleIds = EmployeeShiftAssignment::query()->select('work_style_id')->distinct()->pluck('work_style_id');

        $defaultWorkStyle = $workStyles->firstWhere('is_default', true);
        // 明示的な月次割当が無い全社員は会社のデフォルト働き方にフォールバックする
        // (docs/16-database-schema.md参照)。
        $explicitlyAssignedThisMonth = (int) $assignmentCounts->sum();
        $activeUserCount = User::query()->where('employment_status', 'active')->count();
        $implicitDefaultUserCount = max(0, $activeUserCount - $explicitlyAssignedThisMonth);

        $summaries = [];
        foreach ($workStyles as $workStyle) {
            $appliedEmployeeCount = (int) $assignmentCounts->get($workStyle->id, 0);
            if ($defaultWorkStyle !== null && $workStyle->id === $defaultWorkStyle->id) {
                $appliedEmployeeCount += $implicitDefaultUserCount;
            }

            $summaries[$workStyle->id] = [
                'applied_employee_count' => $appliedEmployeeCount,
                'active_shift_pattern_count' => $workStyle->is_shift_based
                    ? (int) $shiftPatternCounts->get($workStyle->id, 0)
                    : null,
                'configuration_warnings' => $this->configurationWarnings($workStyle, $usedWorkStyleIds),
            ];
        }

        return $summaries;
    }

    /**
     * @param  Collection<int, int>  $usedWorkStyleIds
     * @return list<string>
     */
    private function configurationWarnings(WorkStyle $workStyle, Collection $usedWorkStyleIds): array
    {
        $warnings = [];

        if ($workStyle->is_shift_based && ! $usedWorkStyleIds->contains($workStyle->id)) {
            $warnings[] = 'シフトパターンが割り当てられた勤務予定がまだありません。';
        }

        return $warnings;
    }
}
