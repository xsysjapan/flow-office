<?php

namespace App\Domain\BackOffice\Aggregates;

use App\Domain\BackOffice\Events\BackOfficeTaskAssigned;
use App\Domain\BackOffice\Events\BackOfficeTaskCompleted;
use App\Domain\BackOffice\Events\BackOfficeTaskCreated;
use App\Domain\BackOffice\Events\BackOfficeTaskStatusChanged;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * backoffice_task集約。主キーがコマンド側生成のUUIDのため、行の新規作成自体も
 * BackOfficeTaskProjectorに委ねられる。業務ルール判定(ステータス遷移の可否等)は
 * Handlerがbackoffice_tasks(Projection)の現在値を読んで行う。
 */
class BackOfficeTaskAggregate extends AggregateRoot
{
    public function create(
        string $sourceType,
        string $sourceId,
        string $taskType,
        string $title,
        ?string $assignedDepartment,
        ?string $dueOn,
    ): self {
        $this->recordThat(new BackOfficeTaskCreated(
            sourceType: $sourceType,
            sourceId: $sourceId,
            taskType: $taskType,
            title: $title,
            assignedDepartment: $assignedDepartment,
            dueOn: $dueOn,
        ));

        return $this;
    }

    public function assign(string $assignedUserId, string $assignedByUserId): self
    {
        $this->recordThat(new BackOfficeTaskAssigned(assignedUserId: $assignedUserId, assignedByUserId: $assignedByUserId));

        return $this;
    }

    public function complete(string $completedByUserId, ?string $comment): self
    {
        $this->recordThat(new BackOfficeTaskCompleted(completedByUserId: $completedByUserId, comment: $comment));

        return $this;
    }

    public function changeStatus(string $previousStatus, string $newStatus, string $changedByUserId, ?string $comment): self
    {
        $this->recordThat(new BackOfficeTaskStatusChanged(
            previousStatus: $previousStatus,
            newStatus: $newStatus,
            changedByUserId: $changedByUserId,
            comment: $comment,
        ));

        return $this;
    }
}
