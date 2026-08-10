<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * company_calendar.days_updated (UC-C010: 会社カレンダー日を一括登録・更新する)。
 *
 * `company_calendar_days`は`company_calendar_years`(年度)の子データであり独立した集約を
 * 持たない(attendance_breaksと同じ考え方)。集約ID(aggregateRootUuid)は
 * `company_calendar_years.id`。1回のPUTリクエストで送られた日別設定をまとめて1イベントとして
 * company_calendar_year集約に記録する。
 */
class CompanyCalendarDaysUpdated extends ShouldBeStored
{
    /**
     * @param  list<array{date: string, day_type: string, is_working_day: bool, is_legal_holiday: bool, is_company_holiday: bool, is_public_holiday?: bool, public_holiday_name?: ?string, schedule_state?: string, note: ?string}>  $days
     */
    public function __construct(
        public readonly array $days,
        public readonly string $updatedByUserId,
    ) {}
}
