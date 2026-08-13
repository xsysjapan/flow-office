<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\HolidayCalendarSourceAggregate;
use App\Domain\Attendance\Commands\DisableHolidayCalendarSource;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Models\HolidayCalendarSource;

/**
 * UC-C012 手順5: ソースを無効化する。
 *
 * @implements CommandHandler<DisableHolidayCalendarSource>
 */
class DisableHolidayCalendarSourceHandler implements CommandHandler
{
    public function handle(Command $command): HolidayCalendarSource
    {
        assert($command instanceof DisableHolidayCalendarSource);

        $source = HolidayCalendarSource::query()->findOrFail($command->holidayCalendarSourceId);

        HolidayCalendarSourceAggregate::retrieve($source->id)
            ->disable(disabledByUserId: $command->disabledByUserId)
            ->persist();

        return HolidayCalendarSource::query()->findOrFail($source->id);
    }
}
