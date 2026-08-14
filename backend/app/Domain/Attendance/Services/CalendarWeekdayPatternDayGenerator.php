<?php

namespace App\Domain\Attendance\Services;

use Illuminate\Support\Carbon;

/**
 * 会社カレンダーの曜日休日パターン(`CompanyCalendar::effectiveWeekdayHolidayPattern()`)から、
 * 指定期間の`company_calendar_days`初期データを生成する(UC-C011/UC-C014、指示書6.1節)。
 *
 * `GenerateCompanyCalendarYearsHandler`(バッチ/今すぐ生成)と`CreateCompanyCalendarYearHandler`
 * (手動での年度作成)の両方から共通利用する。
 */
class CalendarWeekdayPatternDayGenerator
{
    /** @return array{day_type: string, is_working_day: bool, is_legal_holiday: bool, is_company_holiday: bool, schedule_state: string} */
    public function resolveForDate(Carbon $date, array $weekdayPattern): array
    {
        $type = $weekdayPattern[(string) $date->dayOfWeekIso];

        return [
            'day_type' => $type === 'working' ? 'weekday' : $type,
            'is_working_day' => $type === 'working',
            'is_legal_holiday' => $type === 'legal_holiday',
            'is_company_holiday' => $type === 'company_holiday',
            'schedule_state' => $type === 'working' ? 'WORK' : 'OFF',
        ];
    }

    /**
     * @param  array<string, string>  $weekdayPattern  ISO曜日("1"〜"7") => working|company_holiday|legal_holiday
     * @return list<array{date: string, day_type: string, is_working_day: bool, is_legal_holiday: bool, is_company_holiday: bool, is_public_holiday: bool, public_holiday_name: ?string, schedule_state: string, note: ?string}>
     */
    public function generate(Carbon $startsOn, Carbon $endsOn, array $weekdayPattern): array
    {
        $days = [];
        $period = $startsOn->copy()->toPeriod($endsOn);

        foreach ($period as $date) {
            $type = $weekdayPattern[(string) $date->dayOfWeekIso];

            $isWorkingDay = $type === 'working';
            $isLegalHoliday = $type === 'legal_holiday';
            $isCompanyHoliday = ! $isWorkingDay;

            $days[] = [
                'date' => $date->toDateString(),
                // 所定休日・法定休日はいずれも既存の挙動(例: 日曜)に合わせて'company_holiday'とする。
                'day_type' => $isWorkingDay ? 'weekday' : 'company_holiday',
                'is_working_day' => $isWorkingDay,
                'is_legal_holiday' => $isLegalHoliday,
                'is_company_holiday' => $isCompanyHoliday,
                'is_public_holiday' => false,
                'public_holiday_name' => null,
                'schedule_state' => $isWorkingDay ? 'WORK' : 'OFF',
                'note' => null,
            ];
        }

        return $days;
    }
}
