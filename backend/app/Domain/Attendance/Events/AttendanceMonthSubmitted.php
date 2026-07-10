<?php

namespace App\Domain\Attendance\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

class AttendanceMonthSubmitted implements DomainEvent
{
    public function __construct(
        public readonly int $attendanceMonthId,
        public readonly int $userId,
        public readonly string $yearMonth,
        public readonly int $approverUserId,
    ) {}

    public function eventType(): string
    {
        return 'attendance.month_submitted';
    }

    public function payload(): array
    {
        return [
            'attendance_month_id' => $this->attendanceMonthId,
            'user_id' => $this->userId,
            'year_month' => $this->yearMonth,
            'approver_user_id' => $this->approverUserId,
        ];
    }
}
