<?php

namespace App\Domain\Attendance\Services;

use App\Models\EmployeeShiftAssignment;
use App\Models\WorkStyle;
use Illuminate\Support\Carbon;

/**
 * UC-C004 手順5: 3交代制シフト表を公開する前に、法定休日不足・連続勤務・月間予定時間を
 * チェックする。判定結果はイベントとして記録せず、公開前確認画面の表示のたびに
 * `employee_shift_assignments` から都度再計算する(LegalHolidayRequirementChecker と同様、
 * 状態変更を伴わない読み取り専用の確認情報のため)。警告があっても公開自体はブロックしない
 * (最終判断は管理者・社労士確認に委ねる。docs/08-usecases-calendar-shift.md UC-C005と同じ方針)。
 */
class ShiftScheduleReviewService
{
    public function __construct(private readonly LegalHolidayRequirementChecker $legalHolidayChecker) {}

    /**
     * @param  list<int>  $userIds
     * @return array{
     *     legal_holiday_shortages: list<array<string, mixed>>,
     *     consecutive_work_violations: list<array{user_id: int, period_start: string, period_end: string, consecutive_days: int, max_allowed: int}>,
     *     monthly_hours_over_cap: list<array{user_id: int, year_month: string, planned_minutes: int, statutory_cap_minutes: int}>,
     * }
     */
    public function review(array $userIds, string $yearMonth): array
    {
        $legalHolidayShortages = [];
        $consecutiveWorkViolations = [];
        $monthlyHoursOverCap = [];

        foreach ($userIds as $userId) {
            foreach ($this->legalHolidayChecker->check($userId, $yearMonth) as $violation) {
                $legalHolidayShortages[] = ['user_id' => $userId, ...$violation];
            }

            $workStyle = $this->resolveWorkStyle($userId, $yearMonth);
            if ($workStyle === null || ! $workStyle->is_shift_based) {
                continue;
            }

            $consecutiveWorkViolations = [
                ...$consecutiveWorkViolations,
                ...$this->checkConsecutiveWork($userId, $workStyle, $yearMonth),
            ];

            $monthlyOverCap = $this->checkMonthlyHours($userId, $yearMonth);
            if ($monthlyOverCap !== null) {
                $monthlyHoursOverCap[] = $monthlyOverCap;
            }
        }

        return [
            'legal_holiday_shortages' => $legalHolidayShortages,
            'consecutive_work_violations' => $consecutiveWorkViolations,
            'monthly_hours_over_cap' => $monthlyHoursOverCap,
        ];
    }

    private function resolveWorkStyle(string $userId, string $yearMonth): ?WorkStyle
    {
        $monthStart = Carbon::createFromFormat('Y-m', $yearMonth)->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();

        return EmployeeShiftAssignment::query()
            ->where('user_id', $userId)
            ->whereDate('work_date', '>=', $monthStart->toDateString())
            ->whereDate('work_date', '<=', $monthEnd->toDateString())
            ->orderBy('work_date')
            ->with('workStyle')
            ->first()
            ?->workStyle;
    }

    /**
     * 会社の就業規則で定めた連続勤務日数の上限(`work_styles.max_consecutive_work_days`、
     * 未設定ならチェックしない)を超える連続勤務がないかを確認する。月をまたぐ連続勤務も
     * 検出できるよう、前後7日を含めた範囲で判定する。
     *
     * @return list<array{user_id: int, period_start: string, period_end: string, consecutive_days: int, max_allowed: int}>
     */
    private function checkConsecutiveWork(string $userId, WorkStyle $workStyle, string $yearMonth): array
    {
        if ($workStyle->max_consecutive_work_days === null) {
            return [];
        }

        $monthStart = Carbon::createFromFormat('Y-m', $yearMonth)->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();
        $windowStart = $monthStart->copy()->subDays(7);
        $windowEnd = $monthEnd->copy()->addDays(7);

        $assignments = EmployeeShiftAssignment::query()
            ->where('user_id', $userId)
            ->whereDate('work_date', '>=', $windowStart->toDateString())
            ->whereDate('work_date', '<=', $windowEnd->toDateString())
            ->orderBy('work_date')
            ->get(['work_date', 'is_working_day']);

        $violations = [];
        $streakStart = null;
        $streakLength = 0;
        $previousDate = null;

        $flushStreak = function () use (&$streakStart, &$streakLength, &$previousDate, &$violations, $userId, $workStyle, $monthStart, $monthEnd): void {
            if ($streakStart !== null && $streakLength > $workStyle->max_consecutive_work_days
                && $streakStart->lte($monthEnd) && $previousDate->gte($monthStart)) {
                $violations[] = [
                    'user_id' => $userId,
                    'period_start' => $streakStart->toDateString(),
                    'period_end' => $previousDate->toDateString(),
                    'consecutive_days' => $streakLength,
                    'max_allowed' => $workStyle->max_consecutive_work_days,
                ];
            }
        };

        foreach ($assignments as $assignment) {
            $date = $assignment->work_date->copy();

            if ($assignment->is_working_day) {
                if ($previousDate !== null && $previousDate->copy()->addDay()->isSameDay($date)) {
                    $streakLength++;
                } else {
                    $streakStart = $date->copy();
                    $streakLength = 1;
                }
                $previousDate = $date;
            } else {
                $flushStreak();
                $streakStart = null;
                $streakLength = 0;
                $previousDate = null;
            }
        }
        $flushStreak();

        return $violations;
    }

    /**
     * 月間の所定労働時間合計が、その月の日数に応じた法定労働時間の総枠
     * (週40時間の平均、`EditEmployeeShiftAssignmentHandler::assertWithinVariablePeriodCap`と同じ考え方)
     * を超えていないかを確認する。
     *
     * @return array{user_id: int, year_month: string, planned_minutes: int, statutory_cap_minutes: int}|null
     */
    private function checkMonthlyHours(string $userId, string $yearMonth): ?array
    {
        $monthStart = Carbon::createFromFormat('Y-m', $yearMonth)->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();

        $plannedMinutes = EmployeeShiftAssignment::query()
            ->where('user_id', $userId)
            ->where('is_working_day', true)
            ->whereDate('work_date', '>=', $monthStart->toDateString())
            ->whereDate('work_date', '<=', $monthEnd->toDateString())
            ->get()
            ->sum(fn (EmployeeShiftAssignment $assignment) => $assignment->plannedWorkMinutes());

        $daysInMonth = $monthStart->daysInMonth;
        $statutoryCapMinutes = (int) round(2400 * $daysInMonth / 7); // 労基法32条: 1週40時間の平均

        if ($plannedMinutes <= $statutoryCapMinutes) {
            return null;
        }

        return [
            'user_id' => $userId,
            'year_month' => $yearMonth,
            'planned_minutes' => $plannedMinutes,
            'statutory_cap_minutes' => $statutoryCapMinutes,
        ];
    }
}
