<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\WorkCalendarAggregate;
use App\Domain\Attendance\Commands\UpdateWorkCalendarDays;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Models\WorkCalendar;
use Illuminate\Support\Collection;

/**
 * UC-C001 手順2〜4: 会社休日・祝日・法定/所定休日を一括登録する。
 *
 * @implements CommandHandler<UpdateWorkCalendarDays>
 */
class UpdateWorkCalendarDaysHandler implements CommandHandler
{
    public function handle(Command $command): Collection
    {
        assert($command instanceof UpdateWorkCalendarDays);

        $workCalendar = WorkCalendar::query()->findOrFail($command->workCalendarId);

        WorkCalendarAggregate::retrieve($command->workCalendarId)
            ->updateDays(days: $command->days, updatedByUserId: $command->updatedByUserId)
            ->persist();

        return $workCalendar->days()->orderBy('date')->get();
    }
}
