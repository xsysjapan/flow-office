<?php

namespace App\Domain\Attendance\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

class AttendanceBreakEnded implements DomainEvent
{
    public function __construct(
        public readonly int $attendanceDayId,
        public readonly int $attendanceBreakId,
        public readonly string $breakEndAt,
    ) {}

    public function eventType(): string
    {
        return 'attendance.break_ended';
    }

    public function payload(): array
    {
        return [
            'attendance_day_id' => $this->attendanceDayId,
            'attendance_break_id' => $this->attendanceBreakId,
            'break_end_at' => $this->breakEndAt,
        ];
    }
}
