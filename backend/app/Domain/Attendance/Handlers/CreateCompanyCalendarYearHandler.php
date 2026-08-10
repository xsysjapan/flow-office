<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\CompanyCalendarYearAggregate;
use App\Domain\Attendance\Commands\CreateCompanyCalendarYear;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Models\CompanyCalendar;
use App\Models\CompanyCalendarYear;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * UC-C009 手順2: 本体配下にカレンダー年度を作成する。
 *
 * @implements CommandHandler<CreateCompanyCalendarYear>
 */
class CreateCompanyCalendarYearHandler implements CommandHandler
{
    public function handle(Command $command): CompanyCalendarYear
    {
        assert($command instanceof CreateCompanyCalendarYear);

        $companyCalendar = CompanyCalendar::query()->findOrFail($command->companyCalendarId);

        if ($companyCalendar->years()->where('fiscal_year', $command->fiscalYear)->exists()) {
            throw ValidationException::withMessages([
                'fiscal_year' => ['この会社カレンダーには既に同じ年度が存在します。'],
            ]);
        }

        $id = (string) Str::uuid();

        CompanyCalendarYearAggregate::retrieve($id)
            ->create(
                companyCalendarId: $command->companyCalendarId,
                fiscalYear: $command->fiscalYear,
                startsOn: $command->startsOn,
                endsOn: $command->endsOn,
                generatedFrom: $command->generatedFrom,
                createdByUserId: $command->createdByUserId,
            )
            ->persist();

        return CompanyCalendarYear::query()->findOrFail($id);
    }
}
