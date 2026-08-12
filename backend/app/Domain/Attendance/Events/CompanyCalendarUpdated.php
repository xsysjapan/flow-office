<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * company_calendar.updated: 会社カレンダー本体の名称・週起算曜日・年度開始月日・
 * 祝日iCalendarソースを編集する。UC-C009手順1の作成後に、これらの設定を後から
 * 変更できるようにするための操作(年度作成とは独立)。
 */
class CompanyCalendarUpdated extends ShouldBeStored
{
    public function __construct(
        public readonly string $name,
        public readonly int $weekStartsOn,
        public readonly int $fiscalYearStartMonth,
        public readonly int $fiscalYearStartDay,
        public readonly ?string $holidayCalendarSourceId,
        public readonly string $updatedByUserId,
    ) {}
}
