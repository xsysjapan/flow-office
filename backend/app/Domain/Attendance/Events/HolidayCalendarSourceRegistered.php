<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * holiday_calendar_source.registered (UC-C012 手順1: 祝日iCalendarソースを登録する)。
 * 集約ID(holiday_calendar_sources.id)は`aggregateRootUuid()`から取得する。
 */
class HolidayCalendarSourceRegistered extends ShouldBeStored
{
    public function __construct(
        public readonly string $name,
        public readonly string $icsUrl,
        public readonly string $registeredByUserId,
    ) {}
}
