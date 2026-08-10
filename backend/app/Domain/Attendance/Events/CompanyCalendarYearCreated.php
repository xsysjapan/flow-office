<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * company_calendar_year.created (UC-C009 手順2: 本体配下にカレンダー年度を作成する)。
 * 集約ID(company_calendar_years.id)は`aggregateRootUuid()`から取得する。
 */
class CompanyCalendarYearCreated extends ShouldBeStored
{
    public function __construct(
        public readonly string $companyCalendarId,
        public readonly int $fiscalYear,
        public readonly string $startsOn,
        public readonly string $endsOn,
        public readonly string $generatedFrom,
        public readonly string $createdByUserId,
    ) {}
}
