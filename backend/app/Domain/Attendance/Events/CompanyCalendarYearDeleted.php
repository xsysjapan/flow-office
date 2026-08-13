<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * company_calendar_year.deleted: カレンダー年度を削除する(UC-C009 手順5、旧「廃止」を
 * 置き換える操作)。集約ID(company_calendar_years.id)は`aggregateRootUuid()`から取得する。
 */
class CompanyCalendarYearDeleted extends ShouldBeStored
{
    public function __construct(
        public readonly string $deletedByUserId,
    ) {}
}
