<?php

namespace App\Domain\Attendance\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

class AttendanceClockedIn implements DomainEvent
{
    public function __construct(
        public readonly int $attendanceDayId,
        public readonly int $userId,
        public readonly string $actualStartAt,
    ) {}

    public function eventType(): string
    {
        return 'attendance.clocked_in';
    }

    public function payload(): array
    {
        return [
            'attendance_day_id' => $this->attendanceDayId,
            'user_id' => $this->userId,
            'actual_start_at' => $this->actualStartAt,
        ];
    }
}
