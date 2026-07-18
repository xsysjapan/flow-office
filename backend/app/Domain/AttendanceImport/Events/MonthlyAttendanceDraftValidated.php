<?php

namespace App\Domain\AttendanceImport\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

class MonthlyAttendanceDraftValidated implements DomainEvent
{
    public function __construct(
        public readonly int $draftId,
        public readonly string $status,
        public readonly int $unconfirmedAiInferredCount,
    ) {}

    public function eventType(): string
    {
        return 'monthly_attendance_draft.validated';
    }

    public function payload(): array
    {
        return [
            'draft_id' => $this->draftId,
            'status' => $this->status,
            'unconfirmed_ai_inferred_count' => $this->unconfirmedAiInferredCount,
        ];
    }
}
