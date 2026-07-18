<?php

namespace App\Domain\AttendanceImport\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

class AttendanceImportDataUploaded implements DomainEvent
{
    public function __construct(
        public readonly int $sessionId,
        public readonly int $itemCount,
    ) {}

    public function eventType(): string
    {
        return 'attendance_import_session.data_uploaded';
    }

    public function payload(): array
    {
        return [
            'session_id' => $this->sessionId,
            'item_count' => $this->itemCount,
        ];
    }
}
