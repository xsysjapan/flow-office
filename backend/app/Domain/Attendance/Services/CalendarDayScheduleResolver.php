<?php

namespace App\Domain\Attendance\Services;

use App\Models\CompanyCalendarDay;
use App\Models\WorkStyle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * UC-C003・UC-C013(calendar_apply): 会社カレンダー日(`company_calendar_days`。無ければ
 * 標準の勤務日扱い)と勤務形態の所定時刻から、対象日1日分の勤務予定を計算する。
 *
 * `GenerateEmployeeCalendarEntriesHandler`(UC-C003本線)と`CalendarBulkOperationPlanner`
 * (UC-C013 calendar_apply)の両方から呼ばれる、計算ロジック本体(CLAUDE.md原則9: 入口ごとに
 * 勤怠計算ロジックを複製しない)。会社カレンダー日の取得条件(公開済み年度のみに絞るか等)は
 * 呼び出し元ごとに異なるため、このクラスでは行わない(`calendarDaysForRange`を呼び出し元が
 * 必要な条件で呼ぶ)。
 */
class CalendarDayScheduleResolver
{
    /**
     * @return Collection<string, CompanyCalendarDay> 日付文字列(Y-m-d)をキーにした会社カレンダー日
     */
    public function calendarDaysForRange(?string $companyCalendarId, string $from, string $to, bool $onlyPublished = false): Collection
    {
        if ($companyCalendarId === null) {
            return collect();
        }

        return CompanyCalendarDay::query()
            ->whereHas('year', function ($query) use ($companyCalendarId, $onlyPublished) {
                $query->where('company_calendar_id', $companyCalendarId);
                if ($onlyPublished) {
                    $query->where('status', 'published');
                }
            })
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->get()
            ->keyBy(fn (CompanyCalendarDay $day) => $day->date->toDateString());
    }

    /**
     * @return array{
     *     day_type: string,
     *     is_working_day: bool,
     *     is_legal_holiday: bool,
     *     is_company_holiday: bool,
     *     planned_start_at: ?Carbon,
     *     planned_end_at: ?Carbon,
     *     planned_break_minutes: int,
     *     planned_break_start_at: ?Carbon,
     *     planned_break_end_at: ?Carbon,
     *     schedule_state: string,
     * }
     */
    public function resolve(WorkStyle $workStyle, Carbon $date, ?CompanyCalendarDay $calendarDay): array
    {
        // Holiday metadata and the work classification used to be independent.  Treat a
        // holiday as non-working defensively so already-published legacy rows are reflected
        // in attendance without requiring the calendar year to be recreated.
        $isWorkingDay = $calendarDay?->is_public_holiday
            ? false
            : ($calendarDay?->is_working_day ?? true);
        $scheduleState = $calendarDay?->is_public_holiday
            ? 'OFF'
            : ($calendarDay?->schedule_state ?? ($isWorkingDay ? 'WORK' : 'OFF'));

        $plannedStartAt = $isWorkingDay && $workStyle->default_start_time
            ? $date->copy()->setTimeFromTimeString($workStyle->default_start_time) : null;
        $plannedEndAt = $isWorkingDay && $workStyle->default_end_time
            ? $date->copy()->setTimeFromTimeString($workStyle->default_end_time) : null;
        $plannedBreakStartAt = $isWorkingDay && $workStyle->default_break_start_time
            ? $date->copy()->setTimeFromTimeString($workStyle->default_break_start_time) : null;
        $plannedBreakEndAt = $isWorkingDay && $workStyle->default_break_end_time
            ? $date->copy()->setTimeFromTimeString($workStyle->default_break_end_time) : null;

        return [
            'day_type' => $calendarDay?->is_public_holiday && ! $calendarDay->is_legal_holiday
                ? 'company_holiday'
                : ($calendarDay?->day_type ?? 'weekday'),
            'is_working_day' => $isWorkingDay,
            'is_legal_holiday' => $calendarDay?->is_legal_holiday ?? false,
            'is_company_holiday' => $calendarDay?->is_public_holiday && ! $calendarDay->is_legal_holiday
                ? true
                : ($calendarDay?->is_company_holiday ?? false),
            'planned_start_at' => $plannedStartAt,
            'planned_end_at' => $plannedEndAt,
            'planned_break_minutes' => $isWorkingDay ? $workStyle->default_break_minutes : 0,
            'planned_break_start_at' => $plannedBreakStartAt,
            'planned_break_end_at' => $plannedBreakEndAt,
            'schedule_state' => $scheduleState,
        ];
    }
}
