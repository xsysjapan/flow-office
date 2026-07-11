<?php

namespace App\Domain\Attendance\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

class AttendancePunchCorrected implements DomainEvent
{
    public function __construct(
        public readonly int $attendancePunchId,
        public readonly int $correctedPunchId,
        public readonly string $punchType,
        public readonly string $punchedAt,
        public readonly string $reason,
        public readonly int $correctedByUserId,
    ) {}

    public function eventType(): string
    {
        return 'attendance_punch.corrected';
    }

    public function payload(): array
    {
        return [
            'attendance_punch_id' => $this->attendancePunchId,
            'corrected_punch_id' => $this->correctedPunchId,
            'punch_type' => $this->punchType,
            'punched_at' => $this->punchedAt,
            'reason' => $this->reason,
            'corrected_by_user_id' => $this->correctedByUserId,
        ];
    }
}
