<?php

namespace App\Domain\BackOffice\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

class BackOfficeTaskCreated implements DomainEvent
{
    public function __construct(
        public readonly string $backOfficeTaskId,
        public readonly string $sourceType,
        public readonly string $sourceId,
        public readonly string $taskType,
        public readonly string $title,
        public readonly ?string $assignedDepartment,
        public readonly ?string $dueOn,
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
            'title' => $this->title,
            'assigned_department' => $this->assignedDepartment,
            'due_on' => $this->dueOn,
        ];
    }
}
