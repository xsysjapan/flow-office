<?php

namespace App\Domain\Attendance\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

class AttendanceDayEdited implements DomainEvent
{
    public function __construct(
        public readonly int $attendanceDayId,
        public readonly string $editedByUserId,
        public readonly string $reason,
    ) {}

    public function eventType(): string
    {
        return 'attendance.day_edited';
    }

    public function payload(): array
    {
        return [
            'attendance_day_id' => $this->attendanceDayId,
            'edited_by_user_id' => $this->editedByUserId,
            'reason' => $this->reason,
        ];
    }
}
