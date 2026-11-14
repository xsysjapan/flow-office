<?php

namespace App\Domain\Attendance\Services;

use App\Models\AttendanceDay;
use App\Models\EmployeeCalendarEntry;
use Illuminate\Support\Carbon;

/**
 * 期間(月・週)の所定労働日数・所定労働時間を、勤務予定から数える(入力の進み具合に左右されない)。
 *
 * - 各日の予定は EffectiveScheduleResolver(日次計算と同じ。勤務予定→働き方のフォールバック→土日の扱い)で解決する。
 * - 所定労働日 = 予定があり、is_working_day が true で、法定休日でなく、会社休日でない日。
 * - 所定労働時間 = 所定労働日について、その日の日次計算があれば prescribed_work_minutes(手動補正を含む。
 *   休暇では減らさない)、無ければ予定の働き方(無ければ WorkStyleFallbackResolver)の prescribed_daily_minutes
 *   (nullなら0)を合計する。
 * - 勤怠日・日次計算・勤務予定は期間で一括取得する。読むのは勤怠文脈のテーブルのみ。
 *
 * docs/changesets/20261114-prescribed-minutes-no-leave-reduction/spec.md 論点7。
 */
class PrescribedScheduleTotalsCalculator
{
    public function __construct(
        private readonly EffectiveScheduleResolver $effectiveScheduleResolver,
        private readonly LegalHolidayResolver $legalHolidayResolver,
        private readonly WorkStyleFallbackResolver $workStyleFallbackResolver,
    ) {}

    /**
     * @return array{prescribed_work_days: int, scheduled_prescribed_work_minutes: int}
     */
    public function calculate(string $userId, string $from, string $to): array
    {
        $fromDate = Carbon::parse($from)->startOfDay();
        $toDate = Carbon::parse($to)->startOfDay();

        $entries = EmployeeCalendarEntry::query()
            ->with('workStyle.calendar')
            ->where('user_id', $userId)
            ->whereDate('work_date', '>=', $fromDate->toDateString())
            ->whereDate('work_date', '<=', $toDate->toDateString())
            ->get()
            ->keyBy(fn (EmployeeCalendarEntry $entry) => $entry->work_date->toDateString());

        $calculations = AttendanceDay::query()
            ->with('calculation')
            ->where('user_id', $userId)
            ->whereDate('work_date', '>=', $fromDate->toDateString())
            ->whereDate('work_date', '<=', $toDate->toDateString())
            ->get()
            ->mapWithKeys(fn (AttendanceDay $day) => [$day->work_date->toDateString() => $day->calculation])
            ->filter();

        $days = 0;
        $minutes = 0;

        for ($date = $fromDate->copy(); $date->lte($toDate); $date->addDay()) {
            $key = $date->toDateString();
            $schedule = $this->effectiveScheduleResolver->resolve($userId, $date->copy(), $entries->get($key));

            if ($schedule === null
                || ! $schedule->is_working_day
                || $schedule->is_company_holiday
                || $this->legalHolidayResolver->isLegalHoliday($schedule)) {
                continue;
            }

            $days++;

            $calculation = $calculations->get($key);
            if ($calculation !== null) {
                $minutes += (int) $calculation->prescribed_work_minutes;

                continue;
            }

            $workStyle = $schedule->workStyle ?? $this->workStyleFallbackResolver->resolveForUser($userId, $date->copy());
            $minutes += (int) ($workStyle?->prescribed_daily_minutes ?? 0);
        }

        return ['prescribed_work_days' => $days, 'scheduled_prescribed_work_minutes' => $minutes];
    }
}
