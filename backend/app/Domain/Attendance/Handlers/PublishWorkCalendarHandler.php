<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Commands\PublishWorkCalendar;
use App\Domain\Attendance\Events\WorkCalendarPublished;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Models\WorkCalendar;

/**
 * UC-C001 手順5: カレンダーを公開する。
 *
 * @implements CommandHandler<PublishWorkCalendar>
 */
class PublishWorkCalendarHandler implements CommandHandler
{
    public function __construct(private readonly EventStore $eventStore) {}

    public function handle(Command $command): WorkCalendar
    {
        assert($command instanceof PublishWorkCalendar);

        $calendar = WorkCalendar::query()->findOrFail($command->workCalendarId);
        $calendar->update(['status' => 'published']);

        $this->eventStore->append(
            aggregateType: 'work_calendar',
            aggregateId: (string) $calendar->id,
            event: new WorkCalendarPublished(
                workCalendarId: $calendar->id,
                publishedByUserId: $command->publishedByUserId,
            ),
        );

        return $calendar;
    }
}
