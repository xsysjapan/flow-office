<?php

namespace App\Domain\AttendanceImport\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

class AttendanceImportSessionCreated implements DomainEvent
{
    public function __construct(
        public readonly int $sessionId,
        public readonly int $userId,
        public readonly string $targetMonth,
        public readonly ?string $sourceFileHash,
    ) {}

    public function eventType(): string
    {
        return 'attendance_import_session.created';
    }

    public function payload(): array
    {
        return [
            'session_id' => $this->sessionId,
            'user_id' => $this->userId,
            'target_month' => $this->targetMonth,
            'source_file_hash' => $this->sourceFileHash,
        ];
    }
}
