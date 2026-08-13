<?php

namespace App\Domain\Attendance\Projectors;

use App\Domain\Attendance\Events\CompanyCalendarCreated;
use App\Domain\Attendance\Events\CompanyCalendarDefaultChanged;
use App\Domain\Attendance\Events\CompanyCalendarDeleted;
use App\Domain\Attendance\Events\CompanyCalendarUpdated;
use App\Models\CompanyCalendar;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * company_calendar.*イベントからcompany_calendars(本体)を作成・更新する
 * (.claude/skills/add-projection参照)。年度・会社カレンダー日は
 * `CompanyCalendarYearProjector`が担当する(UC-C009: 本体と年度の分離)。
 */
class CompanyCalendarProjector extends Projector
{
    public function onCompanyCalendarCreated(CompanyCalendarCreated $event): void
    {
        CompanyCalendar::query()->updateOrCreate(
            ['id' => $event->aggregateRootUuid()],
            [
                'name' => $event->name,
                'week_starts_on' => $event->weekStartsOn,
                'fiscal_year_start_month' => $event->fiscalYearStartMonth,
                'fiscal_year_start_day' => $event->fiscalYearStartDay,
                'weekday_holiday_pattern' => $event->weekdayHolidayPattern,
                'holiday_calendar_source_id' => $event->holidayCalendarSourceId,
                // nullは「カラムの既定値(true)を使う」を意味する(作成時点では
                // 更新元の既存値が無いため、weekday_holiday_patternと違いここで解決する)。
                'allow_daily_holiday_override' => $event->allowDailyHolidayOverride ?? true,
            ],
        );
    }

    public function onCompanyCalendarUpdated(CompanyCalendarUpdated $event): void
    {
        CompanyCalendar::query()
            ->where('id', $event->aggregateRootUuid())
            ->update([
                'name' => $event->name,
                'week_starts_on' => $event->weekStartsOn,
                'fiscal_year_start_month' => $event->fiscalYearStartMonth,
                'fiscal_year_start_day' => $event->fiscalYearStartDay,
                'holiday_calendar_source_id' => $event->holidayCalendarSourceId,
                'weekday_holiday_pattern' => $event->weekdayHolidayPattern,
                'allow_daily_holiday_override' => $event->allowDailyHolidayOverride,
            ]);
    }

    /**
     * work_style.default_changed(WorkStyleProjector)と同じ考え方: 新しいデフォルトを
     * trueにし、旧デフォルトを解除する。
     */
    public function onCompanyCalendarDefaultChanged(CompanyCalendarDefaultChanged $event): void
    {
        if ($event->previousDefaultCompanyCalendarId !== null) {
            CompanyCalendar::query()
                ->where('id', $event->previousDefaultCompanyCalendarId)
                ->update(['is_default' => false]);
        }

        CompanyCalendar::query()
            ->where('id', $event->aggregateRootUuid())
            ->update(['is_default' => true]);
    }

    /**
     * company_calendar_years(cascadeOnDelete)経由でcompany_calendar_daysも
     * 自動的に削除される(company_calendar_days.company_calendar_year_idのDB制約)。
     */
    public function onCompanyCalendarDeleted(CompanyCalendarDeleted $event): void
    {
        CompanyCalendar::query()->whereKey($event->aggregateRootUuid())->delete();
    }
}
