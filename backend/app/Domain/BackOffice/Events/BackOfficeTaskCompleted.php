<?php

namespace App\Domain\BackOffice\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

class BackOfficeTaskCompleted implements DomainEvent
{
    public function __construct(
        public readonly int $backOfficeTaskId,
        public readonly int $completedByUserId,
        public readonly ?string $comment,
    ) {}

    public function eventType(): string
    {
        return 'backoffice_task.completed';
    }

    public function payload(): array
    {
        return [
            'backoffice_task_id' => $this->backOfficeTaskId,
            'completed_by_user_id' => $this->completedByUserId,
            'comment' => $this->comment,
        ];
    }
}
