<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\CompanyCalendarAggregate;
use App\Domain\Attendance\Commands\UpdateCompanyCalendarDays;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Models\CompanyCalendar;
use Illuminate\Support\Collection;

/**
 * UC-C001 手順2〜4: 会社休日・祝日・法定/所定休日を一括登録する。
 *
 * @implements CommandHandler<UpdateCompanyCalendarDays>
 */
class UpdateCompanyCalendarDaysHandler implements CommandHandler
{
    public function handle(Command $command): Collection
    {
        assert($command instanceof UpdateCompanyCalendarDays);

        $companyCalendar = CompanyCalendar::query()->findOrFail($command->companyCalendarId);

        CompanyCalendarAggregate::retrieve($command->companyCalendarId)
            ->updateDays(days: $command->days, updatedByUserId: $command->updatedByUserId)
            ->persist();

        return $companyCalendar->days()->orderBy('date')->get();
    }
}
