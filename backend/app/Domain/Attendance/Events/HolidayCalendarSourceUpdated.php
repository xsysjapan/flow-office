<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * holiday_calendar_source.updated (UC-C012: 祝日iCalendarソースのURL/アップロードファイルを編集する)。
 * 集約ID(holiday_calendar_sources.id)は`aggregateRootUuid()`から取得する。
 */
class HolidayCalendarSourceUpdated extends ShouldBeStored
{
    public function __construct(
        public readonly string $name,
        public readonly string $sourceKind,
        public readonly ?string $icsUrl,
        public readonly ?string $uploadedIcsPath,
        public readonly ?string $uploadedIcsFilename,
        public readonly string $updatedByUserId,
    ) {}
}
