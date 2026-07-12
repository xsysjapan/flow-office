<?php

namespace App\Domain\Attendance\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

/**
 * work_calendar.created (UC-C001 手順1: 年度カレンダーを作成する)。
 */
class WorkCalendarCreated implements DomainEvent
{
    public function __construct(
        public readonly int $workCalendarId,
        public readonly string $name,
        public readonly int $fiscalYear,
        public readonly string $startsOn,
        public readonly string $endsOn,
        public readonly int $weekStartsOn,
        public readonly int $createdByUserId,
    ) {}

    public function eventType(): string
    {
        return 'work_calendar.created';
    }

    public function payload(): array
    {
        return [
            'work_calendar_id' => $this->workCalendarId,
            'name' => $this->name,
            'fiscal_year' => $this->fiscalYear,
            'starts_on' => $this->startsOn,
            'ends_on' => $this->endsOn,
            'week_starts_on' => $this->weekStartsOn,
            'created_by_user_id' => $this->createdByUserId,
        ];
    }
}
