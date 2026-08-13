<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\CompanyCalendarAggregate;
use App\Domain\Attendance\Commands\UpdateCompanyCalendar;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Models\CompanyCalendar;

/**
 * 会社カレンダー本体の名称・週起算曜日・年度開始月日・祝日iCalendarソースを編集する
 * (UC-C009手順1の作成後に行う設定変更。年度作成とは独立)。
 *
 * @implements CommandHandler<UpdateCompanyCalendar>
 */
class UpdateCompanyCalendarHandler implements CommandHandler
{
    public function handle(Command $command): CompanyCalendar
    {
        assert($command instanceof UpdateCompanyCalendar);

        CompanyCalendarAggregate::retrieve($command->companyCalendarId)
            ->update(
                name: $command->name,
                weekStartsOn: $command->weekStartsOn,
                fiscalYearStartMonth: $command->fiscalYearStartMonth,
                fiscalYearStartDay: $command->fiscalYearStartDay,
                holidayCalendarSourceId: $command->holidayCalendarSourceId,
                updatedByUserId: $command->updatedByUserId,
                weekdayHolidayPattern: $command->weekdayHolidayPattern,
                allowDailyHolidayOverride: $command->allowDailyHolidayOverride,
            )
            ->persist();

        return CompanyCalendar::query()->findOrFail($command->companyCalendarId);
    }
}
