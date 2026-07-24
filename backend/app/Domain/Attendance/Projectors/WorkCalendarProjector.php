<?php

namespace App\Domain\Attendance\Projectors;

use App\Domain\Attendance\Events\WorkCalendarCreated;
use App\Domain\Attendance\Events\WorkCalendarDaysUpdated;
use App\Domain\Attendance\Events\WorkCalendarPublished;
use App\Models\WorkCalendar;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * work_calendar.*イベントからwork_calendars / work_calendar_daysを作成・更新する
 * (.claude/skills/add-projection参照)。work_calendar_daysはwork_calendarの子データとして
 * 扱い、WorkCalendarDaysUpdatedイベントに含まれる日別設定をそのまま反映する
 * (attendance_breaksと同じ考え方。docs/29-event-sourcing-framework-migration.md参照)。
 */
class WorkCalendarProjector extends Projector
{
    public function onWorkCalendarCreated(WorkCalendarCreated $event): void
    {
        WorkCalendar::query()->updateOrCreate(
            ['id' => $event->aggregateRootUuid()],
            [
                'name' => $event->name,
                'fiscal_year' => $event->fiscalYear,
                'starts_on' => $event->startsOn,
                'ends_on' => $event->endsOn,
                'week_starts_on' => $event->weekStartsOn,
                'status' => 'draft',
            ],
        );
    }

    public function onWorkCalendarDaysUpdated(WorkCalendarDaysUpdated $event): void
    {
        $calendar = WorkCalendar::query()->findOrFail($event->aggregateRootUuid());

        foreach ($event->days as $day) {
            // 'date' はdateキャストのためDB上はdatetime文字列で保存される。
            // updateOrCreateの厳密一致検索では既存行を見つけられないため、whereDateで明示的に検索する。
            $calendarDay = $calendar->days()->whereDate('date', $day['date'])->first()
                ?? $calendar->days()->make(['date' => $day['date']]);

            $calendarDay->fill([
                'day_type' => $day['day_type'],
                'is_working_day' => $day['is_working_day'] ?? true,
                'is_legal_holiday' => $day['is_legal_holiday'] ?? false,
                'is_company_holiday' => $day['is_company_holiday'] ?? false,
                'note' => $day['note'] ?? null,
            ])->save();
        }
    }

    public function onWorkCalendarPublished(WorkCalendarPublished $event): void
    {
        WorkCalendar::query()->whereKey($event->aggregateRootUuid())->update(['status' => 'published']);
    }
}
