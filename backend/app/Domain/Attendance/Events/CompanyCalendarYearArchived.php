<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * company_calendar_year.archived (UC-C009 手順5: カレンダー年度を廃止する)。
 */
class CompanyCalendarYearArchived extends ShouldBeStored
{
    public function __construct(
        public readonly string $archivedByUserId,
    ) {}
}
