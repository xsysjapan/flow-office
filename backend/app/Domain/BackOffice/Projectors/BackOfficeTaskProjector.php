<?php

namespace App\Domain\BackOffice\Projectors;

use App\Domain\EventSourcing\Contracts\Projector;
use App\Models\BackOfficeTask;
use App\Models\BackOfficeTaskStatus;
use App\Models\StoredEvent;
use Illuminate\Support\Facades\DB;

/**
 * backoffice_task.* イベントから backoffice_tasks を作成・更新する。
 * WorkflowRequestProjector と同様、主キーがUUIDのため行の新規作成(created)自体も
 * このProjectorが担う(.claude/skills/add-projection「集約ルートのUUID化」参照)。
 */
class BackOfficeTaskProjector implements Projector
{
    public function eventTypes(): array
    {
        return [
            'backoffice_task.created',
            'backoffice_task.assigned',
            'backoffice_task.completed',
            'backoffice_task.status_changed',
        ];
    }

    public function project(StoredEvent $event): void
    {
        $payload = $event->payload;
        $id = $payload['backoffice_task_id'];

        match ($event->event_type) {
            'backoffice_task.created' => BackOfficeTask::query()->updateOrCreate(
                ['id' => $id],
                [
                    'source_type' => $payload['source_type'],
                    'source_id' => $payload['source_id'],
                    'task_type' => $payload['task_type'],
                    'title' => $payload['title'],
                    'status' => BackOfficeTaskStatus::NOT_STARTED,
                    'assigned_department' => $payload['assigned_department'],
                    'due_on' => $payload['due_on'],
                ],
            ),
            'backoffice_task.assigned' => BackOfficeTask::query()->whereKey($id)->update([
                'assigned_user_id' => $payload['assigned_user_id'],
                'status' => BackOfficeTaskStatus::IN_REVIEW,
            ]),
            'backoffice_task.completed' => BackOfficeTask::query()->whereKey($id)->update([
                'status' => BackOfficeTaskStatus::COMPLETED,
                'completed_at' => $event->occurred_at,
            ]),
            'backoffice_task.status_changed' => BackOfficeTask::query()->whereKey($id)->update([
                'status' => $payload['new_status'],
            ]),
            default => null,
        };
    }

    public function reset(): void
    {
        DB::table('backoffice_tasks')->truncate();
    }
}
