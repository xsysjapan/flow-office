<?php

namespace App\Domain\Attendance\Services;

use App\Models\EmployeeShiftAssignment;
use App\Models\WorkStyle;
use Illuminate\Support\Carbon;

/**
 * UC-C005: シフト制の勤務形態について、月次でまとめて承認する際に法定休日の
 * 要件(毎週少なくとも1日、または4週間を通じて4日以上の変形休日制)を満たしているか確認する。
 *
 * 注意 (.claude/skills/attendance-calc-review 参照):
 * - どちらの制度を採用するかは会社の就業規則次第であり、work_styles.legal_holiday_rule
 *   にマスタ化する(ハードコードしない)。
 * - 「決める方式」(weekly/four_weeks_four_days)の判定は `employee_shift_assignments.
 *   is_legal_holiday`(勤務予定として与えられた法定休日)を基準にする。実際に休日出勤したか
 *   どうかは別軸の集計(法定休日労働時間)で扱うため、ここでは「休日を与える予定になって
 *   いるか」のみを見る。「決めない方式」(undetermined)はLegalHolidayResolverが指定または
 *   自動推定した日が週内に解決できるかで判定する(UC-C007参照)。
 * - 週・4週の起算はカレンダーマスタ(work_calendars.week_starts_on)/勤務形態マスタ
 *   (work_styles.four_week_period_start_date)を基準にする。
 * - 結果は月次承認画面の警告表示にのみ使い、承認自体はブロックしない。最終判断は
 *   承認者・社労士確認を前提とする(docs/08-usecases-calendar-shift.md 注意点)。
 */
class LegalHolidayRequirementChecker
{
    public function __construct(private readonly LegalHolidayResolver $legalHolidayResolver) {}

    /**
     * @return list<array{rule: string, period_start: string, period_end: string, legal_holiday_count: int, required_count: int}>
     */
    public function check(string $userId, string $yearMonth): array
    {
        $monthStart = Carbon::createFromFormat('Y-m', $yearMonth)->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();

        $workStyle = $this->resolveWorkStyle($userId, $monthStart, $monthEnd);

        if ($workStyle === null || ! $workStyle->is_shift_based) {
            return [];
        }

        return match ($workStyle->legal_holiday_rule) {
            WorkStyle::LEGAL_HOLIDAY_RULE_FOUR_WEEKS_FOUR_DAYS => $this->checkFourWeeksFourDays($userId, $workStyle, $monthStart, $monthEnd),
            WorkStyle::LEGAL_HOLIDAY_RULE_UNDETERMINED => $this->checkUndetermined($userId, $workStyle, $monthStart, $monthEnd),
            default => $this->checkWeekly($userId, $workStyle, $monthStart, $monthEnd),
        };
    }

    private function resolveWorkStyle(string $userId, Carbon $monthStart, Carbon $monthEnd): ?WorkStyle
    {
        return EmployeeShiftAssignment::query()
            ->where('user_id', $userId)
            ->whereDate('work_date', '>=', $monthStart->toDateString())
            ->whereDate('work_date', '<=', $monthEnd->toDateString())
            ->orderBy('work_date')
            ->with('workStyle.calendar')
            ->first()
            ?->workStyle;
    }

    /**
     * @return list<array{rule: string, period_start: string, period_end: string, legal_holiday_count: int, required_count: int}>
     */
    private function checkWeekly(string $userId, WorkStyle $workStyle, Carbon $monthStart, Carbon $monthEnd): array
    {
        $weekStartsOn = $workStyle->calendar?->week_starts_on ?? 1; // ISO: 1=月曜

        $windowStart = $monthStart->copy();
        while ($windowStart->isoWeekday() !== $weekStartsOn) {
            $windowStart->subDay();
        }

        $violations = [];
        $cursor = $windowStart->copy();

        while ($cursor->lte($monthEnd)) {
            $periodEnd = $cursor->copy()->addDays(6);
            $count = $this->countLegalHolidays($userId, $cursor, $periodEnd);

            if ($count < 1) {
                $violations[] = [
                    'rule' => WorkStyle::LEGAL_HOLIDAY_RULE_WEEKLY,
                    'period_start' => $cursor->toDateString(),
                    'period_end' => $periodEnd->toDateString(),
                    'legal_holiday_count' => $count,
                    'required_count' => 1,
                ];
            }

            $cursor->addDays(7);
        }

        return $violations;
    }

    /**
     * @return list<array{rule: string, period_start: string, period_end: string, legal_holiday_count: int, required_count: int}>
     */
    private function checkFourWeeksFourDays(string $userId, WorkStyle $workStyle, Carbon $monthStart, Carbon $monthEnd): array
    {
        $anchor = $workStyle->four_week_period_start_date?->copy() ?? $monthStart->copy()->startOfYear();

        $periodsElapsed = (int) floor($anchor->diffInDays($monthStart, false) / 28);
        $cursor = $anchor->copy()->addDays($periodsElapsed * 28);

        $violations = [];

        while ($cursor->lte($monthEnd)) {
            $periodEnd = $cursor->copy()->addDays(27);
            $count = $this->countLegalHolidays($userId, $cursor, $periodEnd);

            if ($count < 4) {
                $violations[] = [
                    'rule' => WorkStyle::LEGAL_HOLIDAY_RULE_FOUR_WEEKS_FOUR_DAYS,
                    'period_start' => $cursor->toDateString(),
                    'period_end' => $periodEnd->toDateString(),
                    'legal_holiday_count' => $count,
                    'required_count' => 4,
                ];
            }

            $cursor->addDays(28);
        }

        return $violations;
    }

    private function countLegalHolidays(string $userId, Carbon $from, Carbon $to): int
    {
        return EmployeeShiftAssignment::query()
            ->where('user_id', $userId)
            ->where('is_legal_holiday', true)
            ->whereDate('work_date', '>=', $from->toDateString())
            ->whereDate('work_date', '<=', $to->toDateString())
            ->count();
    }

    /**
     * @return list<array{rule: string, period_start: string, period_end: string, legal_holiday_count: int, required_count: int}>
     */
    private function checkUndetermined(string $userId, WorkStyle $workStyle, Carbon $monthStart, Carbon $monthEnd): array
    {
        $weekStartsOn = $workStyle->calendar?->week_starts_on ?? 1;

        $windowStart = $monthStart->copy();
        while ($windowStart->isoWeekday() !== $weekStartsOn) {
            $windowStart->subDay();
        }

        $violations = [];
        $cursor = $windowStart->copy();

        while ($cursor->lte($monthEnd)) {
            $periodEnd = $cursor->copy()->addDays(6);
            $resolvedDate = $this->legalHolidayResolver->resolveDateForWeek($userId, $cursor->copy());
            $count = $resolvedDate !== null ? 1 : 0;

            if ($count < 1) {
                $violations[] = [
                    'rule' => WorkStyle::LEGAL_HOLIDAY_RULE_UNDETERMINED,
                    'period_start' => $cursor->toDateString(),
                    'period_end' => $periodEnd->toDateString(),
                    'legal_holiday_count' => $count,
                    'required_count' => 1,
                ];
            }

            $cursor->addDays(7);
        }

        return $violations;
    }
}
