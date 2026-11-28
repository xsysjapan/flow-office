<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\CompanyCalendarAggregate;
use App\Domain\Attendance\Commands\CreateCompanyCalendar;
use App\Domain\Attendance\Commands\CreateCompanyCalendarYear;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Models\CompanyCalendar;
use Illuminate\Support\Str;

/**
 * UC-C009 手順1: 会社カレンダー本体を作成する。
 *
 * @implements CommandHandler<CreateCompanyCalendar>
 */
class CreateCompanyCalendarHandler implements CommandHandler
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function handle(Command $command): CompanyCalendar
    {
        assert($command instanceof CreateCompanyCalendar);

        // 会社カレンダーは常に高々1件のデフォルトが存在すべき(docs/16-database-schema.md)。
        // 組織内で最初の1件は自動的にデフォルトにする(この判定のみ先に行い、実際の
        // is_default反映はCompanyCalendarDefaultChangedイベント経由でProjectorに委ねる)。
        $isFirst = CompanyCalendar::query()->count() === 0;

        $id = (string) Str::uuid();

        $aggregate = CompanyCalendarAggregate::retrieve($id)
            ->create(
                name: $command->name,
                weekStartsOn: $command->weekStartsOn,
                fiscalYearStartMonth: $command->fiscalYearStartMonth,
                fiscalYearStartDay: $command->fiscalYearStartDay,
                createdByUserId: $command->createdByUserId,
                weekdayHolidayPattern: $command->weekdayHolidayPattern,
                holidayCalendarSourceId: $command->holidayCalendarSourceId,
                // 未指定(null)は既定値のtrue(旧Projectorの読み替えをここに移した)。
                allowsDailyHolidayOverride: $command->allowsDailyHolidayOverride ?? true,
            );

        if ($isFirst) {
            $aggregate->changeDefault(null, $command->createdByUserId);
        }

        // カレンダーを先に persist する(年度の検査・作成がカレンダーの ReadModel を読むため)。
        $aggregate->persist();

        if ($command->fiscalYear !== null) {
            $this->commandBus->dispatch(new CreateCompanyCalendarYear(
                companyCalendarId: $id,
                fiscalYear: $command->fiscalYear,
                startsOn: (string) $command->startsOn,
                endsOn: (string) $command->endsOn,
                generatedFrom: 'manual',
                createdByUserId: $command->createdByUserId,
            ));
        }

        return CompanyCalendar::query()->findOrFail($id);
    }
}
