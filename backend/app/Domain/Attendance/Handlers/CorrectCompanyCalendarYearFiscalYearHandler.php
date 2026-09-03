<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\CompanyCalendarYearAggregate;
use App\Domain\Attendance\Commands\CorrectCompanyCalendarYearFiscalYear;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Models\CompanyCalendarYear;
use Illuminate\Validation\ValidationException;

/**
 * 公開誤りで年度番号を取り違えたカレンダー年度を、ステータスを問わず強制的に訂正する。
 * 実績日(attendance_days等)はfiscal_year/calendar_idを直接参照せず日付レンジでのみ
 * 紐づくため、この訂正では年度番号・開始日・終了日のみを書き換え、実績データには
 * 一切手を加えない。
 *
 * @implements CommandHandler<CorrectCompanyCalendarYearFiscalYear>
 */
class CorrectCompanyCalendarYearFiscalYearHandler implements CommandHandler
{
    public function handle(Command $command): CompanyCalendarYear
    {
        assert($command instanceof CorrectCompanyCalendarYearFiscalYear);

        $companyCalendarYear = CompanyCalendarYear::query()->findOrFail($command->companyCalendarYearId);

        $duplicate = CompanyCalendarYear::query()
            ->where('company_calendar_id', $companyCalendarYear->company_calendar_id)
            ->where('fiscal_year', $command->fiscalYear)
            ->whereKeyNot($companyCalendarYear->id)
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'fiscal_year' => ['同じカレンダーに指定の年度が既に存在します。'],
            ]);
        }

        CompanyCalendarYearAggregate::retrieve($command->companyCalendarYearId)
            ->correctFiscalYear(
                fiscalYear: $command->fiscalYear,
                startsOn: $command->startsOn,
                endsOn: $command->endsOn,
                correctedByUserId: $command->correctedByUserId,
                reason: $command->reason,
            )
            ->persist();

        return CompanyCalendarYear::query()->findOrFail($command->companyCalendarYearId);
    }
}
