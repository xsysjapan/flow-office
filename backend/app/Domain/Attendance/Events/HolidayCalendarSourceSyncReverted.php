<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * holiday_calendar_source.sync_reverted (UC-C012手順4後半: 祝日同期1回分の取消)。
 * 集約ID(holiday_calendar_sources.id)は`aggregateRootUuid()`から取得する。
 *
 * 取消対象の`holiday_calendar_source.synced`イベント(直近1件)の`day_changes`に記録された
 * `previous_is_public_holiday`/`previous_public_holiday_name`をそのまま復元する
 * (dayChangesから逆算しない)。
 */
class HolidayCalendarSourceSyncReverted extends ShouldBeStored
{
    /**
     * @param  list<array{company_calendar_day_id: int, is_public_holiday: bool, public_holiday_name: ?string}>  $dayReverts
     */
    public function __construct(
        public readonly array $dayReverts,
        public readonly int $revertedStoredEventId,
        public readonly string $revertedByUserId,
    ) {}
}
