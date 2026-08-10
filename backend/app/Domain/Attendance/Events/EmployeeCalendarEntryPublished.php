<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * employee_calendar_entry.published (UC-C004 手順6: シフトを公開する)。
 * 下書き状態(is_published=false)だったシフトパターン割当を対象社員に公開する。
 */
class EmployeeCalendarEntryPublished extends ShouldBeStored
{
    public function __construct(
        public readonly string $userId,
        public readonly string $workDate,
        public readonly string $publishedByUserId,
    ) {}
}
