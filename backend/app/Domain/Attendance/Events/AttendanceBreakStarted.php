<?php

namespace App\Domain\Attendance\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

class AttendanceBreakStarted implements DomainEvent
{
    public function __construct(
        public readonly int $attendanceDayId,
        public readonly int $attendanceBreakId,
        public readonly string $breakStartAt,
    ) {}

    public function eventType(): string
    {
        return 'attendance.break_started';
    }

    public function payload(): array
    {
        return [
            'attendance_day_id' => $this->attendanceDayId,
            'attendance_break_id' => $this->attendanceBreakId,
            'break_start_at' => $this->breakStartAt,
        ];
    }
}
