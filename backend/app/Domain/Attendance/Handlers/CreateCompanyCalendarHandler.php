<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\CompanyCalendarAggregate;
use App\Domain\Attendance\Commands\CreateCompanyCalendar;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Models\CompanyCalendar;
use Illuminate\Support\Str;

/**
 * UC-C001 手順1: 年度カレンダーを作成する。
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
                fiscalYear: $command->fiscalYear,
                startsOn: $command->startsOn,
                endsOn: $command->endsOn,
                weekStartsOn: $command->weekStartsOn,
                createdByUserId: $command->createdByUserId,
            )
            ->persist();

        return CompanyCalendar::query()->findOrFail($id);
    }
}
