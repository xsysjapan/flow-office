<?php

namespace App\Domain\BackOffice\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

class BackOfficeTaskCreated implements DomainEvent
{
    public function __construct(
        public readonly int $backOfficeTaskId,
        public readonly string $sourceType,
        public readonly int $sourceId,
        public readonly string $taskType,
    ) {}

    public function eventType(): string
    {
        return 'backoffice_task.created';
    }

    public function payload(): array
    {
        return [
            'backoffice_task_id' => $this->backOfficeTaskId,
            'source_type' => $this->sourceType,
            'source_id' => $this->sourceId,
            'task_type' => $this->taskType,
        ];
    }
}
