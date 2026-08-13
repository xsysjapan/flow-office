<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\CompanyCalendarYearAggregate;
use App\Domain\Attendance\Commands\PublishCompanyCalendarYear;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Models\CompanyCalendarYear;

/**
 * UC-C009 手順3: カレンダー年度を公開する。
 *
 * @implements CommandHandler<PublishCompanyCalendarYear>
 */
class PublishCompanyCalendarYearHandler implements CommandHandler
{
    public function handle(Command $command): CompanyCalendarYear
    {
        assert($command instanceof PublishCompanyCalendarYear);

        CompanyCalendarYear::query()->findOrFail($command->companyCalendarYearId);

        CompanyCalendarYearAggregate::retrieve($command->companyCalendarYearId)
            ->publish(publishedByUserId: $command->publishedByUserId)
            ->persist();

        return CompanyCalendarYear::query()->findOrFail($command->companyCalendarYearId);
    }
}
