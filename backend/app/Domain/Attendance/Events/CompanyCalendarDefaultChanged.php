<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * company_calendar.default_changed (docs/16-database-schema.md: company_calendars.is_default
 * は組織内に常に高々1件のみtrue。新しい会社カレンダーをデフォルトに設定した場合は既存の
 * デフォルトを解除する。work_style.default_changedと同じ考え方)。
 * このイベントの集約ID(`aggregateRootUuid()`)が新しいデフォルトのcompany_calendar_id。
 */
class CompanyCalendarDefaultChanged extends ShouldBeStored
{
    public function __construct(
        public readonly ?string $previousDefaultCompanyCalendarId,
        public readonly string $changedByUserId,
    ) {}
}
