<?php

namespace App\Domain\Attendance\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

/**
 * work_calendar.published (UC-C001 手順5: カレンダーを公開する)。
 */
class WorkCalendarPublished implements DomainEvent
{
    public function __construct(
        public readonly int $workCalendarId,
        public readonly string $publishedByUserId,
    ) {}

    public function eventType(): string
    {
        return 'work_calendar.published';
    }

    public function payload(): array
    {
        return [
            'work_calendar_id' => $this->workCalendarId,
            'published_by_user_id' => $this->publishedByUserId,
        ];
    }
}
