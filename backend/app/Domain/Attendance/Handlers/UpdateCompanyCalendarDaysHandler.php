<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\CompanyCalendarYearAggregate;
use App\Domain\Attendance\Commands\UpdateCompanyCalendarDays;
use App\Domain\Attendance\Services\CalendarWeekdayPatternDayGenerator;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Models\CompanyCalendarYear;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * UC-C010: 会社カレンダー日(祝日・会社休日・法定/所定休日)を一括登録する。
 *
 * @implements CommandHandler<UpdateCompanyCalendarDays>
 */
class UpdateCompanyCalendarDaysHandler implements CommandHandler
{
    public function __construct(
        private readonly CalendarWeekdayPatternDayGenerator $dayGenerator,
    ) {}

    public function handle(Command $command): Collection
    {
        assert($command instanceof UpdateCompanyCalendarDays);

        $companyCalendarYear = CompanyCalendarYear::query()->findOrFail($command->companyCalendarYearId);

        $days = $this->makePublicHolidaysNonWorking(
            $this->applyOverrideLock($companyCalendarYear, $command->days),
        );

        CompanyCalendarYearAggregate::retrieve($command->companyCalendarYearId)
            ->updateDays(days: $days, updatedByUserId: $command->updatedByUserId)
            ->persist();

        return $companyCalendarYear->days()->orderBy('date')->get();
    }

    /**
     * A day marked as a holiday is also a non-working day.  Keep an existing legal-holiday
     * designation; otherwise classify it as a prescribed (company) holiday.
     *
     * @param  list<array<string, mixed>>  $days
     * @return list<array<string, mixed>>
     */
    private function makePublicHolidaysNonWorking(array $days): array
    {
        return array_map(function (array $day): array {
            if (! ($day['is_public_holiday'] ?? false)) {
                return $day;
            }

            $isLegalHoliday = (bool) ($day['is_legal_holiday'] ?? false);

            return array_merge($day, [
                'day_type' => $isLegalHoliday ? 'legal_holiday' : 'company_holiday',
                'is_working_day' => false,
                'is_company_holiday' => ! $isLegalHoliday,
                'schedule_state' => 'OFF',
            ]);
        }, $days);
    }

    /**
     * `allow_daily_holiday_override = false`の会社カレンダーでは、日別の休日区分
     * (is_working_day/is_legal_holiday/is_company_holiday/day_type/schedule_state)を
     * クライアントの入力に関わらず曜日休日パターンから再計算した値で上書きする
     * (防御的にサーバー側でもロックを強制する。フロントエンド側のUI制御とは別に必要)。
     * is_public_holiday/public_holiday_name/noteはクライアントの入力をそのまま通す。
     *
     * `allow_daily_holiday_override`がtrue、またはカレンダー本体が見つからない場合は
     * 既存の挙動のまま(クライアントの入力をそのまま通す)。
     *
     * @param  list<array<string, mixed>>  $days
     * @return list<array<string, mixed>>
     */
    private function applyOverrideLock(CompanyCalendarYear $companyCalendarYear, array $days): array
    {
        $companyCalendar = $companyCalendarYear->companyCalendar;

        if ($companyCalendar === null || $companyCalendar->allow_daily_holiday_override) {
            return $days;
        }

        $pattern = $companyCalendar->effectiveWeekdayHolidayPattern();

        return array_map(function (array $day) use ($pattern): array {
            $type = $pattern[(string) Carbon::parse($day['date'])->dayOfWeekIso];

            $isWorkingDay = $type === 'working';
            $isLegalHoliday = $type === 'legal_holiday';
            $isCompanyHoliday = $type === 'company_holiday';

            return array_merge($day, [
                'day_type' => $type === 'working' ? 'weekday' : $type,
                'is_working_day' => $isWorkingDay,
                'is_legal_holiday' => $isLegalHoliday,
                'is_company_holiday' => $isCompanyHoliday,
                'schedule_state' => $isWorkingDay ? 'WORK' : 'OFF',
            ]);
        }, $days);
    }
}
