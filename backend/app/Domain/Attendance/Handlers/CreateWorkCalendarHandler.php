<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\WorkCalendarAggregate;
use App\Domain\Attendance\Commands\CreateWorkCalendar;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Models\WorkCalendar;
use Illuminate\Support\Str;

/**
 * UC-C001 手順1: 年度カレンダーを作成する。
 *
 * @implements CommandHandler<CreateWorkCalendar>
 */
class CreateWorkCalendarHandler implements CommandHandler
{
    public function handle(Command $command): WorkCalendar
    {
        assert($command instanceof CreateWorkCalendar);

        $id = (string) Str::uuid();

        WorkCalendarAggregate::retrieve($id)
            ->create(
                name: $command->name,
                fiscalYear: $command->fiscalYear,
                startsOn: $command->startsOn,
                endsOn: $command->endsOn,
                weekStartsOn: $command->weekStartsOn,
                createdByUserId: $command->createdByUserId,
            )
            ->persist();

        return WorkCalendar::query()->findOrFail($id);
    }
}
