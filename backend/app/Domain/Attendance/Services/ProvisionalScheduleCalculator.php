<?php

namespace App\Domain\Attendance\Services;

use App\Models\CompanyCalendar;
use App\Models\CompanyCalendarYear;
use App\Models\EmployeeCalendarEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * UC-C014 手順5: 対象年月に対応する`company_calendar_years`(公開済み)が存在しない場合の
 * 読み取りフォールバック。標準の曜日ルール(土日=所定休日、平日=勤務日。祝日ソースは
 * 考慮しない)のみから即席に計算した予定を返す。`company_calendar_years`/
 * `company_calendar_days`には書き込まない。
 */
class ProvisionalScheduleCalculator
{
    /**
     * @param  Collection<int, EmployeeCalendarEntry>  $existingAssignments  既に取得済みの実データ(該当日には補完しない)
     * @return list<EmployeeCalendarEntry> 永続化しない仮の勤務予定(`provisional`動的属性=true)
     */
    public function fillGaps(string $userId, string $from, string $to, Collection $existingAssignments): array
    {
        $existingDates = $existingAssignments->map(fn (EmployeeCalendarEntry $e) => $e->work_date->toDateString())->all();

        $defaultCalendar = CompanyCalendar::query()->where('is_default', true)->first();

        $provisional = [];
        $period = Carbon::parse($from)->toPeriod(Carbon::parse($to));

        foreach ($period as $date) {
            $dateString = $date->toDateString();

            if (in_array($dateString, $existingDates, true)) {
                continue;
            }

            if ($defaultCalendar !== null && $this->isCoveredByPublishedYear($defaultCalendar, $dateString)) {
                // 生成済み・公開済みの年度が存在するのに行が無い日は「未割当」であり、
                // 暫定計算の対象ではない(UC-C013手順6参照)。
                continue;
            }

            // ISO: 1=月〜5=金が勤務日、6=土は所定休日、7=日は所定休日かつ法定休日
            // (GenerateCompanyCalendarYearsHandlerの標準曜日ルールと同じ規則)。
            $isWorkingDay = $date->dayOfWeekIso < 6;
            $isSunday = $date->dayOfWeekIso === 7;

            $entry = new EmployeeCalendarEntry([
                'user_id' => $userId,
                'work_date' => $dateString,
                'work_style_id' => null,
                'shift_pattern_id' => null,
                'day_type' => $isWorkingDay ? 'weekday' : 'company_holiday',
                'is_working_day' => $isWorkingDay,
                'is_legal_holiday' => $isSunday,
                'is_company_holiday' => ! $isWorkingDay,
                'schedule_state' => $isWorkingDay ? 'WORK' : 'OFF',
                'planned_start_at' => null,
                'planned_end_at' => null,
                'planned_break_minutes' => 0,
                'is_published' => true,
                'is_manually_overridden' => false,
            ]);
            $entry->provisional = true;

            $provisional[] = $entry;
        }

        return $provisional;
    }

    private function isCoveredByPublishedYear(CompanyCalendar $calendar, string $date): bool
    {
        return CompanyCalendarYear::query()
            ->where('company_calendar_id', $calendar->id)
            ->where('status', 'published')
            ->whereDate('starts_on', '<=', $date)
            ->whereDate('ends_on', '>=', $date)
            ->exists();
    }
}
