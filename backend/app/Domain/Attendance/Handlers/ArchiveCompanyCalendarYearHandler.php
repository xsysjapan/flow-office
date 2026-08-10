<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\CompanyCalendarYearAggregate;
use App\Domain\Attendance\Commands\ArchiveCompanyCalendarYear;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Models\CompanyCalendarYear;
use Illuminate\Validation\ValidationException;

/**
 * UC-C009 手順5: カレンダー年度を廃止する。対象年度に締め済み月
 * (attendance_months承認済み以降)が1件でもある場合は行えない。
 *
 * @implements CommandHandler<ArchiveCompanyCalendarYear>
 */
class ArchiveCompanyCalendarYearHandler implements CommandHandler
{
    public function handle(Command $command): CompanyCalendarYear
    {
        assert($command instanceof ArchiveCompanyCalendarYear);

        $companyCalendarYear = CompanyCalendarYear::query()->findOrFail($command->companyCalendarYearId);

        if (CompanyCalendarYearLifecycleGuard::hasClosedMonthsWithin($companyCalendarYear)) {
            throw ValidationException::withMessages([
                'company_calendar_year' => ['締め済みの月が含まれる年度は廃止できません。'],
            ]);
        }

        CompanyCalendarYearAggregate::retrieve($command->companyCalendarYearId)
            ->archive(archivedByUserId: $command->archivedByUserId)
            ->persist();

        return CompanyCalendarYear::query()->findOrFail($command->companyCalendarYearId);
    }
}
