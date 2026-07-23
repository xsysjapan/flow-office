<?php

namespace App\Domain\Attendance\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

class AttendanceMonthReturned implements DomainEvent
{
    public function __construct(
        public readonly int $attendanceMonthId,
        public readonly string $returnedByUserId,
        public readonly string $comment,
    ) {}

    public function eventType(): string
    {
        return 'attendance.month_returned';
    }

    public function payload(): array
    {
        return [
            'attendance_month_id' => $this->attendanceMonthId,
            'returned_by_user_id' => $this->returnedByUserId,
            'comment' => $this->comment,
        ];
    }
}
