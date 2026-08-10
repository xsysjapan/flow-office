<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * holiday_calendar_source.sync_failed (UC-C012 手順4: 取得・パース失敗時。
 * `company_calendar_days`は一切変更しない)。
 */
class HolidayCalendarSourceSyncFailed extends ShouldBeStored
{
    public function __construct(
        public readonly string $error,
        public readonly ?string $syncedByUserId,
    ) {}
}
