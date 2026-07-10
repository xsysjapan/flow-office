<?php

namespace App\Domain\Attendance\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

class AttendanceClockedOut implements DomainEvent
{
    public function __construct(
        public readonly int $attendanceDayId,
        public readonly string $actualEndAt,
    ) {}

    public function eventType(): string
    {
        return 'attendance.clocked_out';
    }

    public function payload(): array
    {
        return [
            'attendance_day_id' => $this->attendanceDayId,
            'actual_end_at' => $this->actualEndAt,
        ];
    }
}
