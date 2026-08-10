<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\HolidayCalendarSourceAggregate;
use App\Domain\Attendance\Commands\RegisterHolidayCalendarSource;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Models\HolidayCalendarSource;
use Illuminate\Support\Str;

/**
 * UC-C012 手順1: 祝日iCalendarソースを登録する。
 *
 * @implements CommandHandler<RegisterHolidayCalendarSource>
 */
class RegisterHolidayCalendarSourceHandler implements CommandHandler
{
    public function handle(Command $command): HolidayCalendarSource
    {
        assert($command instanceof RegisterHolidayCalendarSource);

        $id = (string) Str::uuid();

        HolidayCalendarSourceAggregate::retrieve($id)
            ->register(
                name: $command->name,
                icsUrl: $command->icsUrl,
                registeredByUserId: $command->registeredByUserId,
            )
            ->persist();

        return HolidayCalendarSource::query()->findOrFail($id);
    }
}
