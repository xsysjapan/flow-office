<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\CompanyCalendarAggregate;
use App\Domain\Attendance\Commands\CreateCompanyCalendar;
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
            );

        if ($isFirst) {
            $aggregate->changeDefault(null, $command->createdByUserId);
        }

        $aggregate->persist();

        return CompanyCalendar::query()->findOrFail($id);
    }
}
