<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\CompanyCalendarYearAggregate;
use App\Domain\Attendance\Commands\CreateCompanyCalendarYear;
use App\Domain\Attendance\Commands\SyncHolidayCalendarSource;
use App\Domain\Attendance\Commands\UpdateCompanyCalendarDays;
use App\Domain\Attendance\Services\CalendarWeekdayPatternDayGenerator;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Models\CompanyCalendar;
use App\Models\CompanyCalendarYear;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * UC-C009 手順2: 本体配下にカレンダー年度を作成する。
 *
 * 作成直後に、本体の曜日休日パターン(`effectiveWeekdayHolidayPattern()`)から日別設定を
 * 自動生成して反映する(バッチ/今すぐ生成の`GenerateCompanyCalendarYearsHandler`と同じ
 * ロジックを共有サービス経由で使う)。本体に祝日iCalendarソースが設定済みであれば、
 * この新規年度の範囲に限定して同期も行う(`SyncHolidayCalendarSourceHandler`が
 * 取得・パース失敗を自ら吸収するため、ここでは特別な例外処理をしない)。
 *
 * @implements CommandHandler<CreateCompanyCalendarYear>
 */
class CreateCompanyCalendarYearHandler implements CommandHandler
{
    public function __construct(
        private readonly CommandBus $commandBus,
        private readonly CalendarWeekdayPatternDayGenerator $dayGenerator,
    ) {}

    public function handle(Command $command): CompanyCalendarYear
    {
        assert($command instanceof CreateCompanyCalendarYear);

        $companyCalendar = CompanyCalendar::query()->findOrFail($command->companyCalendarId);

        if ($companyCalendar->years()->where('fiscal_year', $command->fiscalYear)->exists()) {
            throw ValidationException::withMessages([
                'fiscal_year' => ['この会社カレンダーには既に同じ年度が存在します。'],
            ]);
        }

        $id = (string) Str::uuid();

        CompanyCalendarYearAggregate::retrieve($id)
            ->create(
                companyCalendarId: $command->companyCalendarId,
                fiscalYear: $command->fiscalYear,
                startsOn: $command->startsOn,
                endsOn: $command->endsOn,
                generatedFrom: $command->generatedFrom,
                createdByUserId: $command->createdByUserId,
            )
            ->persist();

        $days = $this->dayGenerator->generate(
            Carbon::parse($command->startsOn),
            Carbon::parse($command->endsOn),
            $companyCalendar->effectiveWeekdayHolidayPattern(),
        );

        $this->commandBus->dispatch(new UpdateCompanyCalendarDays(
            companyCalendarYearId: $id,
            days: $days,
            updatedByUserId: $command->createdByUserId,
        ));

        if ($companyCalendar->holiday_calendar_source_id !== null) {
            $this->commandBus->dispatch(new SyncHolidayCalendarSource(
                holidayCalendarSourceId: $companyCalendar->holiday_calendar_source_id,
                syncedByUserId: $command->createdByUserId,
                companyCalendarYearId: $id,
            ));
        }

        return CompanyCalendarYear::query()->findOrFail($id);
    }
}
