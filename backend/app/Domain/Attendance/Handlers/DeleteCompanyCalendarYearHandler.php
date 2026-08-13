<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\CompanyCalendarYearAggregate;
use App\Domain\Attendance\Commands\DeleteCompanyCalendarYear;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Models\CompanyCalendarYear;
use Illuminate\Validation\ValidationException;

/**
 * UC-C009 手順5: カレンダー年度を削除する(旧「廃止」を置き換える操作。廃止は
 * ステータスを変えるだけで同じ年度を作り直せなかったため、実際に削除して同じ年度を
 * 再作成できるようにする)。対象年度に締め済み月(attendance_months承認済み以降)が
 * 1件でもある場合は行えない(ArchiveCompanyCalendarYearHandlerと同じガード)。
 *
 * @implements CommandHandler<DeleteCompanyCalendarYear>
 */
class DeleteCompanyCalendarYearHandler implements CommandHandler
{
    public function handle(Command $command): mixed
    {
        assert($command instanceof DeleteCompanyCalendarYear);

        $companyCalendarYear = CompanyCalendarYear::query()->findOrFail($command->companyCalendarYearId);

        if (CompanyCalendarYearLifecycleGuard::hasClosedMonthsWithin($companyCalendarYear)) {
            throw ValidationException::withMessages([
                'company_calendar_year' => ['締め済みの月が含まれる年度は削除できません。'],
            ]);
        }

        CompanyCalendarYearAggregate::retrieve($command->companyCalendarYearId)
            ->delete(deletedByUserId: $command->deletedByUserId)
            ->persist();

        return null;
    }
}
