<?php

namespace App\Domain\Attendance\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

class AttendanceDayDeleted implements DomainEvent
{
    public function __construct(
        public readonly int $attendanceDayId,
        public readonly int $userId,
        public readonly string $workDate,
        public readonly string $reason,
        public readonly int $deletedByUserId,
        public readonly string $punchLogAction,
    ) {}

    public function eventType(): string
    {
        return 'attendance.day_deleted';
    }

    public function payload(): array
    {
        return [
            'attendance_day_id' => $this->attendanceDayId,
            'user_id' => $this->userId,
            'work_date' => $this->workDate,
            'reason' => $this->reason,
            'deleted_by_user_id' => $this->deletedByUserId,
            'punch_log_action' => $this->punchLogAction,
        ];
    }
}
