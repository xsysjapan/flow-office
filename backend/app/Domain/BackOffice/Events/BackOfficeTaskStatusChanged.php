<?php

namespace App\Domain\BackOffice\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

class BackOfficeTaskStatusChanged implements DomainEvent
{
    public function __construct(
        public readonly string $backOfficeTaskId,
        public readonly string $previousStatus,
        public readonly string $newStatus,
        public readonly int $changedByUserId,
        public readonly ?string $comment,
    ) {}

    public function eventType(): string
    {
        return 'backoffice_task.status_changed';
    }

    public function payload(): array
    {
        return [
            'backoffice_task_id' => $this->backOfficeTaskId,
            'previous_status' => $this->previousStatus,
            'new_status' => $this->newStatus,
            'changed_by_user_id' => $this->changedByUserId,
            'comment' => $this->comment,
        ];
    }
}
