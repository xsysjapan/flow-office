<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\CompanyCalendarYearAggregate;
use App\Domain\Attendance\Commands\CreateCompanyCalendarYear;
use App\Domain\Attendance\Commands\GenerateCompanyCalendarYears;
use App\Domain\Attendance\Commands\SyncHolidayCalendarSource;
use App\Domain\Attendance\Commands\UpdateCompanyCalendarDays;
use App\Domain\Attendance\Services\CalendarWeekdayPatternDayGenerator;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Models\CompanyCalendar;
use App\Models\CompanyCalendarYear;
use Illuminate\Support\Carbon;

/**
 * UC-C014: カレンダー年度を定期バッチで生成する(UC-C011「今すぐ生成する」も同じロジックを
 * その場で1回実行する)。
 *
 * 手順1〜3:
 * - 年度が1件も無ければ、本体の`fiscal_year_start_month`/`fiscal_year_start_day`から計算した
 *   現在年度・次年度を`draft`で生成する
 * - 最新年度の`ends_on`が今日から6か月以内で、かつ次の年度が存在しなければ、次年度を
 *   `draft`で生成する
 * - 生成は標準の曜日ルール(土日=所定休日、平日=勤務日)で`company_calendar_days`を作り、
 *   `holiday_calendar_source_id`が設定されていれば同期する(祝日同期が失敗しても曜日ルール
 *   だけで生成は成立させる。`SyncHolidayCalendarSourceHandler`が自ら失敗を吸収するため、
 *   ここでは特別な例外処理をしない)
 * - 本体単位で処理し、1本体の失敗が他の本体の生成に影響しないようにする
 *
 * @implements CommandHandler<GenerateCompanyCalendarYears>
 */
class GenerateCompanyCalendarYearsHandler implements CommandHandler
{
    public function __construct(
        private readonly CommandBus $commandBus,
        private readonly CalendarWeekdayPatternDayGenerator $dayGenerator,
    ) {}

    /**
     * @return list<string> 生成したcompany_calendar_years.idの一覧
     */
    public function handle(Command $command): array
    {
        assert($command instanceof GenerateCompanyCalendarYears);

        $calendars = $command->companyCalendarId === null
            ? CompanyCalendar::query()->where('status', 'active')->get()
            : CompanyCalendar::query()->where('status', 'active')->whereKey($command->companyCalendarId)->get();

        $generatedIds = [];

        foreach ($calendars as $calendar) {
            try {
                $generatedIds = [...$generatedIds, ...$this->generateForCalendar($calendar, $command->isBatch)];
            } catch (\Throwable $e) {
                // 1本体の失敗が他の本体の生成に影響しないようにする(UC-C014手順4)。
                report($e);
            }
        }

        return $generatedIds;
    }

    /**
     * @return list<string>
     */
    private function generateForCalendar(CompanyCalendar $calendar, bool $isBatch): array
    {
        $years = $calendar->years()->orderBy('fiscal_year')->get();
        $today = Carbon::today();
        $generatedIds = [];

        if ($years->isEmpty()) {
            $currentFiscalYear = $this->fiscalYearFor($today, $calendar);
            $generatedIds[] = $this->createYear($calendar, $currentFiscalYear['fiscal_year'], $currentFiscalYear['starts_on'], $currentFiscalYear['ends_on'], $isBatch);

            $nextFiscalYear = $this->fiscalYearFor($currentFiscalYear['ends_on']->copy()->addDay(), $calendar);
            $generatedIds[] = $this->createYear($calendar, $nextFiscalYear['fiscal_year'], $nextFiscalYear['starts_on'], $nextFiscalYear['ends_on'], $isBatch);

            return $generatedIds;
        }

        $latestYear = $years->last();

        if ($today->copy()->addMonths(6)->greaterThanOrEqualTo($latestYear->ends_on)) {
            $nextFiscalYear = $latestYear->fiscal_year + 1;

            if (! $years->contains('fiscal_year', $nextFiscalYear)) {
                $startsOn = $latestYear->ends_on->copy()->addDay();
                $endsOn = $startsOn->copy()->addYear()->subDay();
                $generatedIds[] = $this->createYear($calendar, $nextFiscalYear, $startsOn, $endsOn, $isBatch);
            }
        }

        return $generatedIds;
    }

    /**
     * @return array{fiscal_year: int, starts_on: Carbon, ends_on: Carbon}
     */
    private function fiscalYearFor(Carbon $date, CompanyCalendar $calendar): array
    {
        $startsOnThisCalendarYear = Carbon::create($date->year, $calendar->fiscal_year_start_month, $calendar->fiscal_year_start_day)->startOfDay();

        if ($date->greaterThanOrEqualTo($startsOnThisCalendarYear)) {
            $fiscalYear = $date->year;
            $startsOn = $startsOnThisCalendarYear;
        } else {
            $fiscalYear = $date->year - 1;
            $startsOn = Carbon::create($fiscalYear, $calendar->fiscal_year_start_month, $calendar->fiscal_year_start_day)->startOfDay();
        }

        $endsOn = $startsOn->copy()->addYear()->subDay();

        return ['fiscal_year' => $fiscalYear, 'starts_on' => $startsOn, 'ends_on' => $endsOn];
    }

    private function createYear(CompanyCalendar $calendar, int $fiscalYear, Carbon $startsOn, Carbon $endsOn, bool $isBatch): string
    {
        $year = $this->commandBus->dispatch(new CreateCompanyCalendarYear(
            companyCalendarId: $calendar->id,
            fiscalYear: $fiscalYear,
            startsOn: $startsOn->toDateString(),
            endsOn: $endsOn->toDateString(),
            generatedFrom: 'standard_template',
            createdByUserId: null, // バッチ/システム生成のため操作者無し。
        ));

        $days = $this->dayGenerator->generate($startsOn, $endsOn, $calendar->effectiveWeekdayHolidayPattern());

        $this->commandBus->dispatch(new UpdateCompanyCalendarDays(
            companyCalendarYearId: $year->id,
            days: $days,
            updatedByUserId: null,
        ));

        if ($isBatch) {
            CompanyCalendarYearAggregate::retrieve($year->id)
                ->markBatchGenerated()
                ->persist();
        }

        if ($calendar->holiday_calendar_source_id !== null) {
            $this->commandBus->dispatch(new SyncHolidayCalendarSource(
                holidayCalendarSourceId: $calendar->holiday_calendar_source_id,
                syncedByUserId: null,
            ));
        }

        return CompanyCalendarYear::query()->findOrFail($year->id)->id;
    }
}
