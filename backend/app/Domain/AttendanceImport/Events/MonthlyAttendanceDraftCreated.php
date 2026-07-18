<?php

namespace App\Domain\AttendanceImport\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

class MonthlyAttendanceDraftCreated implements DomainEvent
{
    public function __construct(
        public readonly int $draftId,
        public readonly int $userId,
        public readonly string $targetMonth,
        public readonly int $createdByUserId,
    ) {}

    public function eventType(): string
    {
        return 'monthly_attendance_draft.created';
    }

    public function payload(): array
    {
        return [
            'draft_id' => $this->draftId,
            'user_id' => $this->userId,
            'target_month' => $this->targetMonth,
            'created_by_user_id' => $this->createdByUserId,
        ];
    }
}
