<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * company_calendar_year.unpublished (UC-C009 手順5: カレンダー年度を下書きへ差し戻す)。
 */
class CompanyCalendarYearUnpublished extends ShouldBeStored
{
    public function __construct(
        public readonly string $unpublishedByUserId,
    ) {}
}
