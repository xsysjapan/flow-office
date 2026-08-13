<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * company_calendar.deleted: 会社カレンダー本体を削除する。デフォルトカレンダーや
 * 勤務形態から参照されているカレンダーは削除できない(DeleteCompanyCalendarHandler参照)。
 */
class CompanyCalendarDeleted extends ShouldBeStored
{
    public function __construct(
        public readonly string $deletedByUserId,
    ) {}
}
