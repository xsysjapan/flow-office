<?php

namespace App\Domain\BackOffice\Handlers;

use App\Domain\BackOffice\Commands\CreateBackOfficeTaskFromApproval;
use App\Domain\BackOffice\Events\BackOfficeTaskCreated;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Jobs\SendTeamsNotificationJob;
use App\Models\BackOfficeTask;
use App\Models\BackOfficeTaskStatus;
use App\Models\WorkflowRequest;
use Illuminate\Support\Carbon;

/**
 * UC-B001: バックオフィスタスクを自動作成する。
 *
 * @implements CommandHandler<CreateBackOfficeTaskFromApproval>
 */
class CreateBackOfficeTaskFromApprovalHandler implements CommandHandler
{
    public function __construct(private readonly EventStore $eventStore) {}

    public function handle(Command $command): ?BackOfficeTask
    {
        assert($command instanceof CreateBackOfficeTaskFromApproval);

        $workflowRequest = WorkflowRequest::query()->with('requestType')->findOrFail($command->workflowRequestId);
        $requestType = $workflowRequest->requestType;

        if (! $requestType->requires_backoffice_task) {
            return null;
        }

        $taskType = $requestType->backoffice_task_type ?? 'general_affairs';

        $task = BackOfficeTask::query()->create([
            'source_type' => 'workflow_request',
            'source_id' => $workflowRequest->id,
            'task_type' => $taskType,
            'title' => "{$requestType->name}: {$workflowRequest->title}",
            'status' => BackOfficeTaskStatus::NOT_STARTED,
            'assigned_department' => $requestType->backoffice_department,
            'due_on' => Carbon::now()->addDays(7)->toDateString(),
        ]);

        $this->eventStore->append(
            aggregateType: 'backoffice_task',
            aggregateId: (string) $task->id,
            event: new BackOfficeTaskCreated(
                backOfficeTaskId: $task->id,
                sourceType: 'workflow_request',
                sourceId: $workflowRequest->id,
                taskType: $taskType,
            ),
        );

        SendTeamsNotificationJob::enqueue(
            title: 'バックオフィスタスク作成',
            summary: "「{$task->title}」が{$task->assigned_department}の未担当タスクに追加されました。",
            detailUrl: null,
        );

        return $task;
    }
}
