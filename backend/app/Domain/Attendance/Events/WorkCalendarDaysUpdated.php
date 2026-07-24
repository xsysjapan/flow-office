<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * work_calendar.days_updated (UC-C001 手順2〜4: 会社休日・祝日・法定/所定休日を一括登録する)。
 *
 * `work_calendar_days`はwork_calendarの子データであり独立した集約を持たない
 * (docs/29-event-sourcing-framework-migration.md「WorkCalendar」参照。attendance_breaksと
 * 同じ考え方)。1回のPUTリクエストで送られた日別設定をまとめて1イベントとして
 * work_calendar集約に記録する(1日ごとに別集約・別イベントにしていた旧実装から統合。
 * work_calendar_day単体のidを参照する後続コマンドは存在しないため、1件のイベントに
 * まとめても後続操作に支障はない)。
 */
class WorkCalendarDaysUpdated extends ShouldBeStored
{
    /**
     * @param  list<array{date: string, day_type: string, is_working_day: bool, is_legal_holiday: bool, is_company_holiday: bool, note: ?string}>  $days
     */
    public function __construct(
        public readonly array $days,
        public readonly string $updatedByUserId,
    ) {}
}
