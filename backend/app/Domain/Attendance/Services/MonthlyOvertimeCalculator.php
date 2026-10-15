<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Support\AttendanceDayLeaves;
use App\Models\AttendanceDailyCalculation;
use App\Models\AttendanceDay;
use App\Models\AttendanceDayLeave;
use App\Models\DayClassification;
use App\Models\PaidLeaveType;
use App\Models\SpecialLeaveType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 月60時間超残業(労基法37条、中小企業も2023年4月以降適用)の集計。
 *
 * 注意 (.claude/skills/attendance-calc-review 参照):
 * - `attendance_daily_calculations.statutory_excess_overtime_minutes`(法定外残業。法定休日労働は
 *   含まない)を対象月の月初から都度合算し、60時間を超えた分だけを
 *   `statutory_excess_overtime_over_60h_minutes` とする。
 * - `calculateForDate`(日次画面用、月初からその日までの累計)はProjectionとしては永続化せず、
 *   `attendance_months.snapshot_json`にも合算しない、WeeklyOvertimeCalculatorと同じ表示専用の
 *   参考情報として扱う(月をまたぐ日次編集の反映漏れ防止のため)。
 * - `calculateCategoryTotals`(月次確認画面・月次提出スナップショット用、月全体の合計)は、
 *   週40時間判定(週単位の再集計で日次計上済み分と重複しうる)とは異なり、月全体の
 *   `statutory_excess_overtime_minutes`を「60時間以内」「60時間超」に単純に按分するだけで
 *   二重計上が生じないため、`attendance_months.snapshot_json`に含めてよい。
 * - 法定休日労働はstatutory_excess_overtime_minutes自体に含まれないため、この60時間判定からも
 *   自然に除外される(AttendanceCalculatorが法定休日の日はstatutory_excess_overtime_minutesを0にする)。
 * - 週40時間超残業(`weekly_statutory_excess_overtime_minutes`)は、`WeeklyOvertimeCalculator`が
 *   週ごとに算出した`weekly_statutory_excess_overtime_minutes`(日8時間超で既に計上済みの分を
 *   除いた、週40時間を超える分のみ)を月内の全週で単純合算する。日8時間超(法定外残業)・
 *   月60時間超とは重複しない別区分の残業として加算する。
 */
class MonthlyOvertimeCalculator
{
    private const MONTHLY_STATUTORY_LIMIT_MINUTES = 3600; // 労基法37条: 月60時間

    public function __construct(
        private readonly WeeklyOvertimeCalculator $weeklyOvertimeCalculator,
        private readonly AttendanceDayLeaves $attendanceDayLeaves,
    ) {}

    /**
     * @return array{cumulative_statutory_excess_overtime_minutes: int, statutory_excess_overtime_within_60h_minutes: int, statutory_excess_overtime_over_60h_minutes: int}
     */
    public function calculateForDate(string $userId, string $workDate): array
    {
        $yearMonth = substr($workDate, 0, 7);

        $days = AttendanceDay::query()
            ->where('user_id', $userId)
            ->whereDate('work_date', '>=', "{$yearMonth}-01")
            ->whereDate('work_date', '<=', $workDate)
            ->with('calculation')
            ->orderBy('work_date')
            ->get();

        $cumulativeBeforeToday = 0;
        $todayMinutes = 0;

        foreach ($days as $day) {
            $minutes = $day->calculation->statutory_excess_overtime_minutes ?? 0;
            if ($day->work_date->toDateString() === $workDate) {
                $todayMinutes = $minutes;

                break;
            }
            $cumulativeBeforeToday += $minutes;
        }

        $remainingWithin60h = max(0, self::MONTHLY_STATUTORY_LIMIT_MINUTES - $cumulativeBeforeToday);
        $withinMinutes = min($todayMinutes, $remainingWithin60h);
        $overMinutes = $todayMinutes - $withinMinutes;

        return [
            'cumulative_statutory_excess_overtime_minutes' => $cumulativeBeforeToday + $todayMinutes,
            'statutory_excess_overtime_within_60h_minutes' => $withinMinutes,
            'statutory_excess_overtime_over_60h_minutes' => $overMinutes,
        ];
    }

    /**
     * 月次確認画面・月次提出スナップショット向けの、対象月全体の集計(9区分の合計に加え、
     * 欠勤・有給・特別休暇の月次集計。docs/07-usecases-attendance.md「不就労時間の処理区分」参照)。
     *
     * @return array{work_minutes: int, payroll_work_minutes: int, prescribed_work_minutes: int, statutory_within_overtime_minutes: int, statutory_excess_overtime_minutes: int, statutory_excess_overtime_within_60h_minutes: int, statutory_excess_overtime_over_60h_minutes: int, weekly_statutory_excess_overtime_minutes: int, late_night_work_minutes: int, late_night_prescribed_work_minutes: int, late_night_statutory_within_overtime_minutes: int, late_night_statutory_excess_overtime_minutes: int, legal_holiday_work_minutes: int, prescribed_holiday_work_minutes: int, late_night_legal_holiday_work_minutes: int, late_night_prescribed_holiday_work_minutes: int, absence_days: int, absence_minutes: int, paid_leave_days: float, paid_leave_minutes: int, special_leave_days: float, special_leave_minutes: int, worked_days: int, work_days_weekday: int, work_days_prescribed_holiday: int, work_days_legal_holiday: int, weekday_regular_work_minutes: int, weekday_statutory_within_overtime_minutes: int, weekday_statutory_excess_overtime_minutes: int, weekday_late_night_prescribed_work_minutes: int, weekday_late_night_statutory_within_overtime_minutes: int, weekday_late_night_statutory_excess_overtime_minutes: int, prescribed_holiday_statutory_within_overtime_minutes: int, prescribed_holiday_statutory_excess_overtime_minutes: int, prescribed_holiday_late_night_prescribed_work_minutes: int, prescribed_holiday_late_night_statutory_excess_overtime_minutes: int}
     */
    public function calculateCategoryTotals(string $userId, string $yearMonth): array
    {
        $days = AttendanceDay::query()
            ->where('user_id', $userId)
            ->where('work_date', 'like', "{$yearMonth}%")
            ->with('calculation')
            ->get();

        $calculations = $days->pluck('calculation')->filter()->values();

        $statutoryOvertimeTotal = (int) $calculations->sum('prescribed_statutory_excess_work_minutes')
            + (int) $calculations->sum('non_prescribed_statutory_excess_work_minutes');

        $weeklyOvertimeMinutes = array_sum(array_column(
            $this->weeklyOvertimeCalculator->calculateForMonth($userId, $yearMonth),
            'weekly_statutory_excess_overtime_minutes',
        ));

        // 欠勤時間がその日の所定労働時間以上の日を「終日欠勤」とみなして日数に数える
        // (1時間の欠勤を1日欠勤として扱わないため。docs/07-usecases-attendance.md参照)。
        $absenceDays = $calculations
            ->filter(fn ($calculation) => $calculation->prescribed_work_minutes > 0 && $calculation->absence_minutes >= $calculation->prescribed_work_minutes)
            ->count();

        // 平日/所定休日/法定休日別の内訳(勤怠CSV出力の区分別項目向け。docs/07-usecases-attendance.md参照)。
        // `AttendanceCalculator`は法定休日の労働を所定内/所定外に分解しない(全額を
        // legal_holiday_work_minutes/late_night_legal_holiday_work_minutesとして法定外扱いする)ため、
        // 法定休日の「所定時間」に相当する内訳は存在しない(呼び出し側で0として扱う)。
        $byClassification = $days->groupBy('day_classification');
        $weekdayCalcs = $this->calculationsForClassification($byClassification, DayClassification::WORKING_DAY);
        $prescribedHolidayCalcs = $this->calculationsForClassification($byClassification, DayClassification::PRESCRIBED_HOLIDAY);
        $legalHolidayCalcs = $this->calculationsForClassification($byClassification, DayClassification::LEGAL_HOLIDAY);

        return [
            'work_minutes' => (int) $calculations->sum('work_minutes'),
            'payroll_work_minutes' => (int) $calculations->sum('payroll_work_minutes'),
            'prescribed_work_minutes' => (int) $calculations->sum('prescribed_work_minutes'),
            'prescribed_statutory_within_work_minutes' => (int) $calculations->sum('prescribed_statutory_within_work_minutes'),
            'non_prescribed_statutory_within_work_minutes' => (int) $calculations->sum('non_prescribed_statutory_within_work_minutes'),
            'prescribed_statutory_excess_work_minutes' => (int) $calculations->sum('prescribed_statutory_excess_work_minutes'),
            'non_prescribed_statutory_excess_work_minutes' => (int) $calculations->sum('non_prescribed_statutory_excess_work_minutes'),
            'statutory_within_overtime_minutes' => (int) $calculations->sum('statutory_within_overtime_minutes'),
            'statutory_excess_overtime_minutes' => $statutoryOvertimeTotal,
            'statutory_excess_overtime_within_60h_minutes' => min($statutoryOvertimeTotal, self::MONTHLY_STATUTORY_LIMIT_MINUTES),
            'statutory_excess_overtime_over_60h_minutes' => max(0, $statutoryOvertimeTotal - self::MONTHLY_STATUTORY_LIMIT_MINUTES),
            'weekly_statutory_excess_overtime_minutes' => $weeklyOvertimeMinutes,
            'late_night_work_minutes' => (int) $calculations->sum('late_night_work_minutes'),
            'late_night_prescribed_work_minutes' => (int) $calculations->sum('late_night_prescribed_work_minutes'),
            'late_night_statutory_within_overtime_minutes' => (int) $calculations->sum('late_night_statutory_within_overtime_minutes'),
            'late_night_statutory_excess_overtime_minutes' => (int) $calculations->sum('late_night_statutory_excess_overtime_minutes'),
            'late_night_prescribed_statutory_within_work_minutes' => (int) $calculations->sum('late_night_prescribed_statutory_within_work_minutes'),
            'late_night_non_prescribed_statutory_within_work_minutes' => (int) $calculations->sum('late_night_non_prescribed_statutory_within_work_minutes'),
            'late_night_prescribed_statutory_excess_work_minutes' => (int) $calculations->sum('late_night_prescribed_statutory_excess_work_minutes'),
            'late_night_non_prescribed_statutory_excess_work_minutes' => (int) $calculations->sum('late_night_non_prescribed_statutory_excess_work_minutes'),
            'legal_holiday_work_minutes' => (int) $calculations->sum('legal_holiday_work_minutes'),
            'prescribed_holiday_work_minutes' => (int) $calculations->sum('prescribed_holiday_work_minutes'),
            'late_night_legal_holiday_work_minutes' => (int) $calculations->sum('late_night_legal_holiday_work_minutes'),
            'late_night_prescribed_holiday_work_minutes' => (int) $calculations->sum('late_night_prescribed_holiday_work_minutes'),
            'absence_days' => $absenceDays,
            'absence_minutes' => (int) $calculations->sum('absence_minutes'),
            'paid_leave_days' => (float) $calculations->sum('paid_leave_days'),
            'paid_leave_minutes' => (int) $calculations->sum('paid_leave_minutes'),
            'special_leave_days' => (float) $calculations->sum('special_leave_days'),
            'special_leave_minutes' => (int) $calculations->sum('special_leave_minutes'),
            // 労働日数(実際に勤務した日数)。休日区分を問わず、work_minutes > 0 の日を数える
            // (work_days_weekday/prescribed_holiday/legal_holidayの合計と一致する)。
            'worked_days' => $calculations->filter(fn ($calculation) => $calculation->work_minutes > 0)->count(),
            'work_days_weekday' => $weekdayCalcs->filter(fn ($calculation) => $calculation->work_minutes > 0)->count(),
            'work_days_prescribed_holiday' => $prescribedHolidayCalcs->filter(fn ($calculation) => $calculation->work_minutes > 0)->count(),
            'work_days_legal_holiday' => $legalHolidayCalcs->filter(fn ($calculation) => $calculation->work_minutes > 0)->count(),
            // 所定内出勤時間(work_minutesから所定外・法定外残業を除いた分)。AttendanceCalculatorが
            // 深夜時間帯を3区分に分解する際の`regularWorkMinutes`と同じ定義。
            'weekday_regular_work_minutes' => (int) $weekdayCalcs->sum('work_minutes')
                - (int) $weekdayCalcs->sum('statutory_within_overtime_minutes')
                - (int) $weekdayCalcs->sum('statutory_excess_overtime_minutes'),
            'weekday_statutory_within_overtime_minutes' => (int) $weekdayCalcs->sum('statutory_within_overtime_minutes'),
            'weekday_prescribed_statutory_within_work_minutes' => (int) $weekdayCalcs->sum('prescribed_statutory_within_work_minutes'),
            'weekday_non_prescribed_statutory_within_work_minutes' => (int) $weekdayCalcs->sum('non_prescribed_statutory_within_work_minutes'),
            'weekday_prescribed_statutory_excess_work_minutes' => (int) $weekdayCalcs->sum('prescribed_statutory_excess_work_minutes'),
            'weekday_non_prescribed_statutory_excess_work_minutes' => (int) $weekdayCalcs->sum('non_prescribed_statutory_excess_work_minutes'),
            'weekday_statutory_excess_overtime_minutes' => (int) $weekdayCalcs->sum('statutory_excess_overtime_minutes'),
            'weekday_late_night_prescribed_work_minutes' => (int) $weekdayCalcs->sum('late_night_prescribed_work_minutes'),
            'weekday_late_night_statutory_within_overtime_minutes' => (int) $weekdayCalcs->sum('late_night_statutory_within_overtime_minutes'),
            'weekday_late_night_statutory_excess_overtime_minutes' => (int) $weekdayCalcs->sum('late_night_statutory_excess_overtime_minutes'),
            'prescribed_holiday_statutory_within_overtime_minutes' => (int) $prescribedHolidayCalcs->sum('statutory_within_overtime_minutes'),
            'prescribed_holiday_prescribed_statutory_within_work_minutes' => (int) $prescribedHolidayCalcs->sum('prescribed_statutory_within_work_minutes'),
            'prescribed_holiday_non_prescribed_statutory_within_work_minutes' => (int) $prescribedHolidayCalcs->sum('non_prescribed_statutory_within_work_minutes'),
            'prescribed_holiday_prescribed_statutory_excess_work_minutes' => (int) $prescribedHolidayCalcs->sum('prescribed_statutory_excess_work_minutes'),
            'prescribed_holiday_non_prescribed_statutory_excess_work_minutes' => (int) $prescribedHolidayCalcs->sum('non_prescribed_statutory_excess_work_minutes'),
            'prescribed_holiday_statutory_excess_overtime_minutes' => (int) $prescribedHolidayCalcs->sum('statutory_excess_overtime_minutes'),
            'prescribed_holiday_late_night_prescribed_work_minutes' => (int) $prescribedHolidayCalcs->sum('late_night_prescribed_work_minutes'),
            'prescribed_holiday_late_night_statutory_excess_overtime_minutes' => (int) $prescribedHolidayCalcs->sum('late_night_statutory_excess_overtime_minutes'),
        ];
    }

    /**
     * @return Collection<int, AttendanceDailyCalculation>
     */
    private function calculationsForClassification(Collection $byClassification, string $dayClassification): Collection
    {
        return $byClassification->get($dayClassification, collect())->pluck('calculation')->filter()->values();
    }

    /**
     * 対象月の特別休暇を`special_leave_type_id`ごとに内訳集計する(月次確認画面向け)。
     * 入力は勤怠の休暇ビュー(attendance_day_leaves)の特別休暇のうち承認済み(request_status=approved)の行。
     * 現行と同じく承認済みだけを集計する(申請中は含めない。日次計算のtotalsは申請中も含むため両者は一致しない)。
     * 日数は全休=1.0・半休=0.5(時間単位以外)、時間は時間単位(hourly)の分数の合計とする
     * (AttendanceCalculatorの休暇入力(LeaveCalculationInput)と同じ値)。
     *
     * @return list<array{special_leave_type_id: int, special_leave_type_name: string|null, days: float, minutes: int}>
     */
    public function calculateSpecialLeaveBreakdown(string $userId, string $yearMonth): array
    {
        $periodStart = Carbon::parse("{$yearMonth}-01")->startOfMonth();

        $leaves = collect($this->attendanceDayLeaves->activeForRange(
            $userId,
            $periodStart->toDateString(),
            $periodStart->copy()->endOfMonth()->toDateString(),
        ))->filter(fn (array $leave) => $leave['leave_kind'] === AttendanceDayLeave::KIND_SPECIAL
            && $leave['special_leave_type_id'] !== null
            && $leave['request_status'] === AttendanceDayLeave::STATUS_APPROVED);

        $names = SpecialLeaveType::query()
            ->whereIn('id', $leaves->pluck('special_leave_type_id')->unique()->values())
            ->pluck('name', 'id');

        return $leaves
            ->groupBy(fn (array $leave) => (int) $leave['special_leave_type_id'])
            ->map(function (Collection $leavesForType, int $typeId) use ($names): array {
                $days = 0.0;
                $minutes = 0;
                foreach ($leavesForType as $leave) {
                    if ($leave['unit'] === PaidLeaveType::HOURLY) {
                        $minutes += (int) ($leave['minutes'] ?? 0);

                        continue;
                    }
                    $days += $leave['unit'] === PaidLeaveType::FULL ? 1.0 : 0.5;
                }

                return [
                    'special_leave_type_id' => $typeId,
                    'special_leave_type_name' => $names[$typeId] ?? null,
                    'days' => $days,
                    'minutes' => $minutes,
                ];
            })
            ->values()
            ->all();
    }
}
