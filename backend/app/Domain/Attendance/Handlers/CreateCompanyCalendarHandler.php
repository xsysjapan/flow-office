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

        $id = (string) Str::uuid();

        CompanyCalendarAggregate::retrieve($id)
            ->create(
                name: $command->name,
                weekStartsOn: $command->weekStartsOn,
                fiscalYearStartMonth: $command->fiscalYearStartMonth,
                fiscalYearStartDay: $command->fiscalYearStartDay,
                createdByUserId: $command->createdByUserId,
            )
            ->persist();

        return CompanyCalendar::query()->findOrFail($id);
    }
}
