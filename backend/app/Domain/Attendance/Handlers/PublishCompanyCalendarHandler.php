<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\CompanyCalendarAggregate;
use App\Domain\Attendance\Commands\PublishCompanyCalendar;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Models\CompanyCalendar;

/**
 * UC-C001 手順5: カレンダーを公開する。
 *
 * @implements CommandHandler<PublishCompanyCalendar>
 */
class PublishCompanyCalendarHandler implements CommandHandler
{
    public function handle(Command $command): CompanyCalendar
    {
        assert($command instanceof PublishCompanyCalendar);

        CompanyCalendar::query()->findOrFail($command->companyCalendarId);

        CompanyCalendarAggregate::retrieve($command->companyCalendarId)
            ->publish(publishedByUserId: $command->publishedByUserId)
            ->persist();

        return CompanyCalendar::query()->findOrFail($command->companyCalendarId);
    }
}
