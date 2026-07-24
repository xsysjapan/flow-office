<?php

namespace App\Domain\BackOffice\Projectors;

use App\Domain\BackOffice\Events\BackOfficeTaskAssigned;
use App\Domain\BackOffice\Events\BackOfficeTaskCompleted;
use App\Domain\BackOffice\Events\BackOfficeTaskCreated;
use App\Domain\BackOffice\Events\BackOfficeTaskStatusChanged;
use App\Models\BackOfficeTask;
use App\Models\BackOfficeTaskStatus;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * backoffice_task.* イベントから backoffice_tasks を作成・更新する。主キーがコマンド側
 * 生成のUUIDのため、行の新規作成(created)自体もこのProjectorが担う。
 */
class BackOfficeTaskProjector extends Projector
{
    public function onBackOfficeTaskCreated(BackOfficeTaskCreated $event): void
    {
        BackOfficeTask::query()->updateOrCreate(
            ['id' => $event->aggregateRootUuid()],
            [
                'source_type' => $event->sourceType,
                'source_id' => $event->sourceId,
                'task_type' => $event->taskType,
                'title' => $event->title,
                'status' => BackOfficeTaskStatus::NOT_STARTED,
                'assigned_department' => $event->assignedDepartment,
                'due_on' => $event->dueOn,
            ],
        );
    }

    public function onBackOfficeTaskAssigned(BackOfficeTaskAssigned $event): void
    {
        BackOfficeTask::query()->whereKey($event->aggregateRootUuid())->update([
            'assigned_user_id' => $event->assignedUserId,
            'status' => BackOfficeTaskStatus::IN_REVIEW,
        ]);
    }

    public function onBackOfficeTaskCompleted(BackOfficeTaskCompleted $event): void
    {
        BackOfficeTask::query()->whereKey($event->aggregateRootUuid())->update([
            'status' => BackOfficeTaskStatus::COMPLETED,
            'completed_at' => $event->createdAt(),
        ]);
    }

    public function onBackOfficeTaskStatusChanged(BackOfficeTaskStatusChanged $event): void
    {
        BackOfficeTask::query()->whereKey($event->aggregateRootUuid())->update([
            'status' => $event->newStatus,
        ]);
    }
}
