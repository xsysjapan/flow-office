<?php

namespace App\Domain\BackOffice\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

class BackOfficeTaskAssigned implements DomainEvent
{
    public function __construct(
        public readonly string $backOfficeTaskId,
        public readonly int $assignedUserId,
        public readonly int $assignedByUserId,
    ) {}

    public function eventType(): string
    {
        return 'backoffice_task.assigned';
    }

    public function payload(): array
    {
        return [
            'backoffice_task_id' => $this->backOfficeTaskId,
            'assigned_user_id' => $this->assignedUserId,
            'assigned_by_user_id' => $this->assignedByUserId,
        ];
    }
}
