<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * company_calendar_year.published (UC-C009 手順3: カレンダー年度を公開する)。
 */
class CompanyCalendarYearPublished extends ShouldBeStored
{
    public function __construct(
        public readonly string $publishedByUserId,
    ) {}
}
