<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\CompanyCalendarYearAggregate;
use App\Domain\Attendance\Commands\UpdateCompanyCalendarDays;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Models\CompanyCalendarYear;
use Illuminate\Support\Collection;

/**
 * UC-C010: 会社カレンダー日(祝日・会社休日・法定/所定休日)を一括登録する。
 *
 * @implements CommandHandler<UpdateCompanyCalendarDays>
 */
class UpdateCompanyCalendarDaysHandler implements CommandHandler
{
    public function handle(Command $command): Collection
    {
        assert($command instanceof UpdateCompanyCalendarDays);

        $companyCalendarYear = CompanyCalendarYear::query()->findOrFail($command->companyCalendarYearId);

        CompanyCalendarYearAggregate::retrieve($command->companyCalendarYearId)
            ->updateDays(days: $command->days, updatedByUserId: $command->updatedByUserId)
            ->persist();

        return $companyCalendarYear->days()->orderBy('date')->get();
    }
}
