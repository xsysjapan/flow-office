<?php

namespace App\Domain\AttendanceImport\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

class AttendanceImportSessionPreviewed implements DomainEvent
{
    public function __construct(
        public readonly int $sessionId,
        public readonly int $itemCount,
        public readonly int $itemsWithBlockingDifferences,
    ) {}

    public function eventType(): string
    {
        return 'attendance_import_session.previewed';
    }

    public function payload(): array
    {
        return [
            'session_id' => $this->sessionId,
            'item_count' => $this->itemCount,
            'items_with_blocking_differences' => $this->itemsWithBlockingDifferences,
        ];
    }
}
