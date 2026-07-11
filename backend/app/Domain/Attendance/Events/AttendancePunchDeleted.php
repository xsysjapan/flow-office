<?php

namespace App\Domain\Attendance\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

class AttendancePunchDeleted implements DomainEvent
{
    public function __construct(
        public readonly int $attendancePunchId,
        public readonly string $reason,
        public readonly int $deletedByUserId,
    ) {}

    public function eventType(): string
    {
        return 'attendance_punch.deleted';
    }

    public function payload(): array
    {
        return [
            'attendance_punch_id' => $this->attendancePunchId,
            'reason' => $this->reason,
            'deleted_by_user_id' => $this->deletedByUserId,
        ];
    }
}
