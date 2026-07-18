<?php

namespace App\Domain\AttendanceImport\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

class AttendanceImportSessionApplied implements DomainEvent
{
    public function __construct(
        public readonly int $sessionId,
        public readonly int $draftId,
        public readonly int $appliedByUserId,
    ) {}

    public function eventType(): string
    {
        return 'attendance_import_session.applied';
    }

    public function payload(): array
    {
        return [
            'session_id' => $this->sessionId,
            'draft_id' => $this->draftId,
            'applied_by_user_id' => $this->appliedByUserId,
        ];
    }
}
