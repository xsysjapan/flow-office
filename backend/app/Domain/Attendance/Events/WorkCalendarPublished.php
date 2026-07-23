<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * work_calendar.published (UC-C001 手順5: カレンダーを公開する)。
 */
class WorkCalendarPublished extends ShouldBeStored
{
    public function __construct(
        public readonly string $publishedByUserId,
    ) {}
}
