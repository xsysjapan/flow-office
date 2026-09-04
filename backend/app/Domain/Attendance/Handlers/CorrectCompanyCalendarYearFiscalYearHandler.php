<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\CompanyCalendarYearAggregate;
use App\Domain\Attendance\Commands\CorrectCompanyCalendarYearFiscalYear;
use App\Domain\Attendance\Commands\SyncHolidayCalendarSource;
use App\Domain\Attendance\Commands\UpdateCompanyCalendarDays;
use App\Domain\Attendance\Services\CalendarWeekdayPatternDayGenerator;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Models\CompanyCalendarYear;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * 公開誤りで年度番号を取り違えたカレンダー年度を、ステータスを問わず強制的に訂正する。
 * 実績日(attendance_days等)はfiscal_year/calendar_idを直接参照せず日付レンジでのみ
 * 紐づくため、この訂正では実績データには一切手を加えない。
 *
 * 一方で`company_calendar_days`(所定休日・法定休日・祝日)は年度の期間(starts_on〜ends_on)
 * に対して生成される子データであるため、期間そのものを訂正した場合は放置すると
 * 誤った(訂正前の)期間の日別データが残ってしまう。そのため、訂正後の新しい期間に対して
 * 本体の曜日休日パターンから日別データを作り直し(`CreateCompanyCalendarYearHandler`と
 * 同じ生成ロジックを共有)、祝日iCalendarソースが設定されていればこの年度の新しい期間に
 * 限定して同期し直す。手動で編集していた日別データがあれば破棄される点は、通常の
 * 「再作成」操作(draft限定)と同じ挙動である。
 *
 * @implements CommandHandler<CorrectCompanyCalendarYearFiscalYear>
 */
class CorrectCompanyCalendarYearFiscalYearHandler implements CommandHandler
{
    public function __construct(
        private readonly CommandBus $commandBus,
        private readonly CalendarWeekdayPatternDayGenerator $dayGenerator,
    ) {}

    public function handle(Command $command): CompanyCalendarYear
    {
        assert($command instanceof CorrectCompanyCalendarYearFiscalYear);

        $companyCalendarYear = CompanyCalendarYear::query()->findOrFail($command->companyCalendarYearId);

        $duplicate = CompanyCalendarYear::query()
            ->where('company_calendar_id', $companyCalendarYear->company_calendar_id)
            ->where('fiscal_year', $command->fiscalYear)
            ->whereKeyNot($companyCalendarYear->id)
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'fiscal_year' => ['同じカレンダーに指定の年度が既に存在します。'],
            ]);
        }

        CompanyCalendarYearAggregate::retrieve($command->companyCalendarYearId)
            ->correctFiscalYear(
                fiscalYear: $command->fiscalYear,
                startsOn: $command->startsOn,
                endsOn: $command->endsOn,
                correctedByUserId: $command->correctedByUserId,
                reason: $command->reason,
            )
            ->persist();

        $companyCalendarYear->refresh();
        $companyCalendar = $companyCalendarYear->companyCalendar;

        // 訂正前の期間分の日別データは新しい期間とはもはや対応しないため、作り直す前に破棄する。
        $companyCalendarYear->days()->delete();

        $days = $this->dayGenerator->generate(
            Carbon::parse($command->startsOn),
            Carbon::parse($command->endsOn),
            $companyCalendar->effectiveWeekdayHolidayPattern(),
        );

        $this->commandBus->dispatch(new UpdateCompanyCalendarDays(
            companyCalendarYearId: $companyCalendarYear->id,
            days: $days,
            updatedByUserId: $command->correctedByUserId,
        ));

        if ($companyCalendar->holiday_calendar_source_id !== null) {
            $this->commandBus->dispatch(new SyncHolidayCalendarSource(
                holidayCalendarSourceId: $companyCalendar->holiday_calendar_source_id,
                syncedByUserId: $command->correctedByUserId,
                companyCalendarYearId: $companyCalendarYear->id,
            ));
        }

        return CompanyCalendarYear::query()->findOrFail($command->companyCalendarYearId);
    }
}
