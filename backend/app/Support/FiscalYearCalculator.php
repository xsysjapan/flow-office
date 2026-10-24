<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * 年度開始月日から、ある日付が属する年度(年度・開始日・終了日)を求める。
 * 会社カレンダーの年度生成(`GenerateCompanyCalendarYearsHandler`)と、有給の一斉付与日
 * (`ScheduleCandidateGenerator`)で同じ規則を使うための共通の関数。
 */
final class FiscalYearCalculator
{
    /**
     * @return array{fiscal_year: int, starts_on: Carbon, ends_on: Carbon}
     */
    public static function fiscalYearFor(Carbon $date, int $startMonth, int $startDay): array
    {
        $startsOnThisCalendarYear = Carbon::create($date->year, $startMonth, $startDay)->startOfDay();

        if ($date->greaterThanOrEqualTo($startsOnThisCalendarYear)) {
            $fiscalYear = $date->year;
            $startsOn = $startsOnThisCalendarYear;
        } else {
            $fiscalYear = $date->year - 1;
            $startsOn = Carbon::create($fiscalYear, $startMonth, $startDay)->startOfDay();
        }

        $endsOn = $startsOn->copy()->addYear()->subDay();

        return ['fiscal_year' => $fiscalYear, 'starts_on' => $startsOn, 'ends_on' => $endsOn];
    }

    /**
     * `$after`より厳密に後で最初の年度開始日(`$after`自身が年度開始日なら翌年の同日)。
     */
    public static function nextStartAfter(Carbon $after, int $startMonth, int $startDay): Carbon
    {
        $current = self::fiscalYearFor($after->copy()->startOfDay(), $startMonth, $startDay)['starts_on'];

        return Carbon::create($current->year + 1, $startMonth, $startDay)->startOfDay();
    }
}
