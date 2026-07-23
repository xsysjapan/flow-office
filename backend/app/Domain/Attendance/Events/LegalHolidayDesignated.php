<?php

namespace App\Domain\Attendance\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

/**
 * attendance.legal_holiday_designated
 *
 * 法定休日「決めない方式」における、特定の週の法定休日の指定・再指定。
 */
class LegalHolidayDesignated implements DomainEvent
{
    public function __construct(
        public readonly string $userId,
        public readonly string $weekStartDate,
        public readonly ?string $previousDesignatedDate,
        public readonly string $designatedDate,
        public readonly string $reason,
        public readonly string $designatedByUserId,
    ) {}

    public function eventType(): string
    {
        return 'attendance.legal_holiday_designated';
    }

    public function payload(): array
    {
        return [
            'user_id' => $this->userId,
            'week_start_date' => $this->weekStartDate,
            'previous_designated_date' => $this->previousDesignatedDate,
            'designated_date' => $this->designatedDate,
            'reason' => $this->reason,
            'designated_by_user_id' => $this->designatedByUserId,
        ];
    }
}
