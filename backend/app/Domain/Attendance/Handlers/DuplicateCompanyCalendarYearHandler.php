<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\CompanyCalendarYearAggregate;
use App\Domain\Attendance\Commands\DuplicateCompanyCalendarYear;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Models\CompanyCalendarDay;
use App\Models\CompanyCalendarYear;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * UC-C009 手順4: 既存年度を複製して翌年度を作成する。曜日区分のみ引き継ぎ、祝日・会社休日
 * (手動上書きされたcompany_calendar_days)は引き継がない。
 *
 * 曜日区分は元年度の各曜日について、祝日を除いた日の`schedule_state`の多数決で決める
 * (標準の曜日ルール(平日勤務・土日休日)であれば自然にその通りになる)。
 *
 * @implements CommandHandler<DuplicateCompanyCalendarYear>
 */
class DuplicateCompanyCalendarYearHandler implements CommandHandler
{
    public function handle(Command $command): CompanyCalendarYear
    {
        assert($command instanceof DuplicateCompanyCalendarYear);

        $source = CompanyCalendarYear::query()->with('days')->findOrFail($command->sourceCompanyCalendarYearId);

        $targetFiscalYear = $source->fiscal_year + 1;

        if ($source->companyCalendar->years()->where('fiscal_year', $targetFiscalYear)->exists()) {
            throw ValidationException::withMessages([
                'fiscal_year' => ['この会社カレンダーには既に同じ年度が存在します。'],
            ]);
        }

        $startsOn = Carbon::parse($source->starts_on)->addYear();
        $endsOn = Carbon::parse($source->ends_on)->addYear();

        $weekdayScheduleState = $this->buildWeekdayScheduleStateMap($source->days);

        $days = [];
        $period = $startsOn->copy()->toPeriod($endsOn);
        foreach ($period as $date) {
            $scheduleState = $weekdayScheduleState[$date->dayOfWeekIso] ?? CompanyCalendarDay::SCHEDULE_WORK;

            $days[] = [
                'date' => $date->toDateString(),
                'day_type' => $scheduleState === CompanyCalendarDay::SCHEDULE_WORK ? 'weekday' : 'company_holiday',
                'is_working_day' => $scheduleState === CompanyCalendarDay::SCHEDULE_WORK,
                'is_legal_holiday' => false,
                'is_company_holiday' => $scheduleState === CompanyCalendarDay::SCHEDULE_OFF,
                'is_public_holiday' => false,
                'public_holiday_name' => null,
                'schedule_state' => $scheduleState,
                'note' => null,
            ];
        }

        $id = (string) Str::uuid();

        CompanyCalendarYearAggregate::retrieve($id)
            ->duplicateFrom(
                companyCalendarId: $source->company_calendar_id,
                fiscalYear: $targetFiscalYear,
                startsOn: $startsOn->toDateString(),
                endsOn: $endsOn->toDateString(),
                days: $days,
                createdByUserId: $command->createdByUserId,
            )
            ->persist();

        return CompanyCalendarYear::query()->findOrFail($id);
    }

    /**
     * @param  Collection<int, CompanyCalendarDay>  $days
     * @return array<int, string> ISO曜日(1=月〜7=日) => schedule_state
     */
    private function buildWeekdayScheduleStateMap($days): array
    {
        $countsByWeekday = [];

        foreach ($days as $day) {
            if ($day->is_public_holiday) {
                continue;
            }

            $weekday = $day->date->dayOfWeekIso;
            $countsByWeekday[$weekday] ??= [];
            $countsByWeekday[$weekday][$day->schedule_state] = ($countsByWeekday[$weekday][$day->schedule_state] ?? 0) + 1;
        }

        $map = [];
        foreach ($countsByWeekday as $weekday => $counts) {
            arsort($counts);
            $map[$weekday] = array_key_first($counts);
        }

        return $map;
    }
}
