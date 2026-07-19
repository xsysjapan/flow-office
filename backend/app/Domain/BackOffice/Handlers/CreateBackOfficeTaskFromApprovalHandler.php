<?php

namespace App\Domain\BackOffice\Handlers;

use App\Domain\BackOffice\Commands\CreateBackOfficeTaskFromApproval;
use App\Domain\BackOffice\Events\BackOfficeTaskCreated;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Domain\Notification\NotificationRecipients;
use App\Jobs\SendNotificationJob;
use App\Models\BackOfficeTask;
use App\Models\Role;
use App\Models\WorkflowRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

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
        $title = "{$requestType->name}: {$workflowRequest->title}";

        // 主キーがコマンド側生成のUUIDのため、backoffice_tasks行はここで直接作成せず
        // BackOfficeTaskProjectorに委ねる(.claude/skills/add-projection参照)。
        $backOfficeTaskId = (string) Str::uuid();

        $this->eventStore->append(
            aggregateType: 'backoffice_task',
            aggregateId: $backOfficeTaskId,
            event: new BackOfficeTaskCreated(
                backOfficeTaskId: $backOfficeTaskId,
                sourceType: 'workflow_request',
                sourceId: $workflowRequest->id,
                taskType: $taskType,
                title: $title,
                assignedDepartment: $requestType->backoffice_department,
                dueOn: Carbon::now()->addDays(7)->toDateString(),
            ),
        );

        $task = BackOfficeTask::query()->findOrFail($backOfficeTaskId);
        $summary = "「{$task->title}」が{$task->assigned_department}の未担当タスクに追加されました。";

        // まだ個人には割り当てられていない(UC-B002で割当時に本人へ通知する)ため、
        // バックオフィス担当ロール全体へ通知する。
        foreach (NotificationRecipients::byRoles([Role::BACKOFFICE_STAFF]) as $recipient) {
            SendNotificationJob::enqueue(
                recipient: $recipient,
                title: 'バックオフィスタスク作成',
                summary: $summary,
                detailUrl: null,
            );
        }

        return $task;
    }
}
