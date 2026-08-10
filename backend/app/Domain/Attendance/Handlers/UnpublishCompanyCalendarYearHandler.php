<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\CompanyCalendarYearAggregate;
use App\Domain\Attendance\Commands\UnpublishCompanyCalendarYear;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Models\CompanyCalendarYear;
use Illuminate\Validation\ValidationException;

/**
 * UC-C009 手順5: カレンダー年度を下書きへ差し戻す。対象年度に締め済み月
 * (attendance_months承認済み以降)が1件でもある場合は行えない。
 *
 * @implements CommandHandler<UnpublishCompanyCalendarYear>
 */
class UnpublishCompanyCalendarYearHandler implements CommandHandler
{
    public function handle(Command $command): CompanyCalendarYear
    {
        assert($command instanceof UnpublishCompanyCalendarYear);

        $companyCalendarYear = CompanyCalendarYear::query()->findOrFail($command->companyCalendarYearId);

        if (CompanyCalendarYearLifecycleGuard::hasClosedMonthsWithin($companyCalendarYear)) {
            throw ValidationException::withMessages([
                'company_calendar_year' => ['締め済みの月が含まれる年度は下書きへ差し戻せません。'],
            ]);
        }

        CompanyCalendarYearAggregate::retrieve($command->companyCalendarYearId)
            ->unpublish(unpublishedByUserId: $command->unpublishedByUserId)
            ->persist();

        return CompanyCalendarYear::query()->findOrFail($command->companyCalendarYearId);
    }
}
