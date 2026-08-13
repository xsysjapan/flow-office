<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * company_calendar.created (UC-C009 手順1: 会社カレンダー本体を作成する)。
 * 集約ID(company_calendars.id)は`aggregateRootUuid()`から取得するため、コンストラクタ引数には
 * 持たせない(Workflow/BackOfficeと同じ整理)。年度依存フィールド(fiscal_year/starts_on/
 * ends_on)はcompany_calendar_year集約が持つため、本イベントには含めない。
 */
class CompanyCalendarCreated extends ShouldBeStored
{
    public function __construct(
        public readonly string $name,
        public readonly int $weekStartsOn,
        public readonly int $fiscalYearStartMonth,
        public readonly int $fiscalYearStartDay,
        public readonly string $createdByUserId,
        public readonly ?array $weekdayHolidayPattern = null,
        public readonly ?string $holidayCalendarSourceId = null,
        public readonly ?bool $allowDailyHolidayOverride = null,
    ) {}
}
