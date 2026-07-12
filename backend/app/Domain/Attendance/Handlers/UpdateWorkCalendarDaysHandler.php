<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Commands\UpdateWorkCalendarDays;
use App\Domain\Attendance\Events\WorkCalendarDayUpdated;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Models\WorkCalendar;
use Illuminate\Support\Collection;

/**
 * UC-C001 手順2〜4: 会社休日・祝日・法定/所定休日を一括登録する。
 *
 * @implements CommandHandler<UpdateWorkCalendarDays>
 */
class UpdateWorkCalendarDaysHandler implements CommandHandler
{
    public function __construct(private readonly EventStore $eventStore) {}

    public function handle(Command $command): Collection
    {
        assert($command instanceof UpdateWorkCalendarDays);

        $workCalendar = WorkCalendar::query()->findOrFail($command->workCalendarId);
        $updated = collect();

        foreach ($command->days as $day) {
            // 'date' はdateキャストのためDB上はdatetime文字列で保存される。
            // updateOrCreateの厳密一致検索では既存行を見つけられないため、whereDateで明示的に検索する。
            $calendarDay = $workCalendar->days()->whereDate('date', $day['date'])->first()
                ?? $workCalendar->days()->make(['date' => $day['date']]);

            $calendarDay->fill([
                'day_type' => $day['day_type'],
                'is_working_day' => $day['is_working_day'] ?? true,
                'is_legal_holiday' => $day['is_legal_holiday'] ?? false,
                'is_company_holiday' => $day['is_company_holiday'] ?? false,
                'note' => $day['note'] ?? null,
            ])->save();

            $this->eventStore->append(
                aggregateType: 'work_calendar_day',
                aggregateId: (string) $calendarDay->id,
                event: new WorkCalendarDayUpdated(
                    workCalendarDayId: $calendarDay->id,
                    workCalendarId: $workCalendar->id,
                    date: $calendarDay->date->toDateString(),
                    dayType: $calendarDay->day_type,
                    isWorkingDay: $calendarDay->is_working_day,
                    isLegalHoliday: $calendarDay->is_legal_holiday,
                    isCompanyHoliday: $calendarDay->is_company_holiday,
                    note: $calendarDay->note,
                    updatedByUserId: $command->updatedByUserId,
                ),
            );

            $updated->push($calendarDay);
        }

        return $updated;
    }
}
