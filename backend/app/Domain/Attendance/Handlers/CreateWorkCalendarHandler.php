<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Commands\CreateWorkCalendar;
use App\Domain\Attendance\Events\WorkCalendarCreated;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Models\WorkCalendar;

/**
 * UC-C001 手順1: 年度カレンダーを作成する。
 *
 * @implements CommandHandler<CreateWorkCalendar>
 */
class CreateWorkCalendarHandler implements CommandHandler
{
    public function __construct(private readonly EventStore $eventStore) {}

    public function handle(Command $command): WorkCalendar
    {
        assert($command instanceof CreateWorkCalendar);

        $calendar = WorkCalendar::query()->create([
            'name' => $command->name,
            'fiscal_year' => $command->fiscalYear,
            'starts_on' => $command->startsOn,
            'ends_on' => $command->endsOn,
            'week_starts_on' => $command->weekStartsOn,
            'status' => 'draft',
        ]);

        $this->eventStore->append(
            aggregateType: 'work_calendar',
            aggregateId: (string) $calendar->id,
            event: new WorkCalendarCreated(
                workCalendarId: $calendar->id,
                name: $command->name,
                fiscalYear: $command->fiscalYear,
                startsOn: $command->startsOn,
                endsOn: $command->endsOn,
                weekStartsOn: $command->weekStartsOn,
                createdByUserId: $command->createdByUserId,
            ),
        );

        return $calendar;
    }
}
