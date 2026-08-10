<?php

namespace App\Domain\Attendance\Projectors;

use App\Domain\Attendance\Events\CompanyCalendarCreated;
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
            ],
        );
    }
}
