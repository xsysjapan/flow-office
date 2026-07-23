<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\WorkCalendarAggregate;
use App\Domain\Attendance\Commands\PublishWorkCalendar;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Models\WorkCalendar;

/**
 * UC-C001 手順5: カレンダーを公開する。
 *
 * @implements CommandHandler<PublishWorkCalendar>
 */
class PublishWorkCalendarHandler implements CommandHandler
{
    public function handle(Command $command): WorkCalendar
    {
        assert($command instanceof PublishWorkCalendar);

        WorkCalendar::query()->findOrFail($command->workCalendarId);

        WorkCalendarAggregate::retrieve($command->workCalendarId)
            ->publish(publishedByUserId: $command->publishedByUserId)
            ->persist();

        return WorkCalendar::query()->findOrFail($command->workCalendarId);
    }
}
