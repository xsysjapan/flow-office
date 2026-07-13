<?php

namespace App\Domain\Attendance\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

/**
 * work_calendar_day.updated (UC-C001 手順2〜3: 会社休日・祝日・法定/所定休日を登録する)。
 */
class WorkCalendarDayUpdated implements DomainEvent
{
    public function __construct(
        public readonly int $workCalendarDayId,
        public readonly int $workCalendarId,
        public readonly string $date,
        public readonly string $dayType,
        public readonly bool $isWorkingDay,
        public readonly bool $isLegalHoliday,
        public readonly bool $isCompanyHoliday,
        public readonly ?string $note,
        public readonly int $updatedByUserId,
    ) {}

    public function eventType(): string
    {
        return 'work_calendar_day.updated';
    }

    public function payload(): array
    {
        return [
            'work_calendar_day_id' => $this->workCalendarDayId,
            'work_calendar_id' => $this->workCalendarId,
            'date' => $this->date,
            'day_type' => $this->dayType,
            'is_working_day' => $this->isWorkingDay,
            'is_legal_holiday' => $this->isLegalHoliday,
            'is_company_holiday' => $this->isCompanyHoliday,
            'note' => $this->note,
            'updated_by_user_id' => $this->updatedByUserId,
        ];
    }
}
