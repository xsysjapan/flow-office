<?php

namespace App\Domain\AttendanceImport\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

class MonthlyAttendanceDraftUpdated implements DomainEvent
{
    public function __construct(
        public readonly int $draftId,
        public readonly int $version,
        public readonly int $acceptedCount,
        public readonly int $rejectedCount,
        public readonly int $updatedByUserId,
    ) {}

    public function eventType(): string
    {
        return 'monthly_attendance_draft.updated';
    }

    public function payload(): array
    {
        return [
            'draft_id' => $this->draftId,
            'version' => $this->version,
            'accepted_count' => $this->acceptedCount,
            'rejected_count' => $this->rejectedCount,
            'updated_by_user_id' => $this->updatedByUserId,
        ];
    }
}
