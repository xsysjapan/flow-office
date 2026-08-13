<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * holiday_calendar_source.deleted (UC-C012): 祝日iCalendarソースを削除する。
 * 無効化(disable)したソースを再度有効化する手段が無く、登録し直すしかなかったため、
 * 不要になったソースを削除できるようにする。集約ID(holiday_calendar_sources.id)は
 * `aggregateRootUuid()`から取得する。
 */
class HolidayCalendarSourceDeleted extends ShouldBeStored
{
    public function __construct(
        public readonly string $deletedByUserId,
    ) {}
}
