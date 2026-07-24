<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * work_calendar.created (UC-C001 手順1: 年度カレンダーを作成する)。
 * 集約ID(work_calendars.id)は`aggregateRootUuid()`から取得するため、コンストラクタ引数には
 * 持たせない(Workflow/BackOfficeと同じ整理)。
 */
class WorkCalendarCreated extends ShouldBeStored
{
    public function __construct(
        public readonly string $name,
        public readonly int $fiscalYear,
        public readonly string $startsOn,
        public readonly string $endsOn,
        public readonly int $weekStartsOn,
        public readonly string $createdByUserId,
    ) {}
}
