<?php

namespace App\Domain\Attendance\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

class AttendanceMonthApproved implements DomainEvent
{
    public function __construct(
        public readonly int $attendanceMonthId,
        public readonly string $approvedByUserId,
    ) {}

    public function eventType(): string
    {
        return 'attendance.month_approved';
    }

    public function payload(): array
    {
        return [
            'attendance_month_id' => $this->attendanceMonthId,
            'approved_by_user_id' => $this->approvedByUserId,
        ];
    }
}
