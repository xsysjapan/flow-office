<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * holiday_calendar_source.disabled (UC-C012 手順5: ソースを無効化する。以後の自動同期は
 * 停止するが、既に反映済みの祝日データは保持する)。
 */
class HolidayCalendarSourceDisabled extends ShouldBeStored
{
    public function __construct(
        public readonly string $disabledByUserId,
    ) {}
}
