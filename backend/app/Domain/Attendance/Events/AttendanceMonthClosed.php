<?php

namespace App\Domain\Attendance\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

class AttendanceMonthClosed implements DomainEvent
{
    public function __construct(
        public readonly int $attendanceMonthId,
        public readonly int $closedByUserId,
    ) {}

    public function eventType(): string
    {
        return 'attendance.month_closed';
    }

    public function payload(): array
    {
        return [
            'attendance_month_id' => $this->attendanceMonthId,
            'closed_by_user_id' => $this->closedByUserId,
        ];
    }
}
