<?php

namespace App\Domain\BackOffice\Handlers;

use App\Domain\BackOffice\Commands\ChangeBackOfficeTaskStatus;
use App\Domain\BackOffice\Events\BackOfficeTaskCompleted;
use App\Domain\BackOffice\Events\BackOfficeTaskStatusChanged;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\BackOfficeTask;
use App\Models\BackOfficeTaskStatus;
use App\Models\WorkflowRequest;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * UC-B003: 処理ステータスを更新する。
 *
 * @implements CommandHandler<ChangeBackOfficeTaskStatus>
 */
class ChangeBackOfficeTaskStatusHandler implements CommandHandler
{
    public function __construct(private readonly EventStore $eventStore) {}

    public function handle(Command $command): BackOfficeTask
    {
        assert($command instanceof ChangeBackOfficeTaskStatus);

        if (! in_array($command->newStatus, BackOfficeTaskStatus::all(), true)) {
            throw new InvalidArgumentException("不正なステータス [{$command->newStatus}] です。");
        }

        $task = BackOfficeTask::query()->with('source.requestType')->findOrFail($command->backOfficeTaskId);
        $this->assertAllowedTransition($task, $command->newStatus);

        $previousStatus = $task->status;
        $task->status = $command->newStatus;

        if ($command->newStatus === BackOfficeTaskStatus::COMPLETED) {
            $task->completed_at = Carbon::now();
        }
        $task->save();

        if ($command->newStatus === BackOfficeTaskStatus::COMPLETED) {
            $this->eventStore->append(
                aggregateType: 'backoffice_task',
                aggregateId: (string) $task->id,
                event: new BackOfficeTaskCompleted(
                    backOfficeTaskId: $task->id,
                    completedByUserId: $command->changedByUserId,
                    comment: $command->comment,
                ),
            );
        } else {
            $this->eventStore->append(
                aggregateType: 'backoffice_task',
                aggregateId: (string) $task->id,
                event: new BackOfficeTaskStatusChanged(
                    backOfficeTaskId: $task->id,
                    previousStatus: $previousStatus,
                    newStatus: $command->newStatus,
                    changedByUserId: $command->changedByUserId,
                    comment: $command->comment,
                ),
            );
        }

        return $task;
    }

    /**
     * タスク種別(`task_type`)ごとに処理フロー・ステータス遷移が異なるため、遷移の許可は
     * 発生源(`workflow_requests.request_type_id`)の`allowed_status_transitions`
     * マスタで定義する(docs/11-usecases-backoffice.md「実装上のポイント」)。未設定(null)
     * の申請種別、またはworkflow_request以外が発生源のタスクは従来通り制限なしとする。
     */
    private function assertAllowedTransition(BackOfficeTask $task, string $newStatus): void
    {
        if ($task->status === $newStatus) {
            return;
        }

        if (! $task->source instanceof WorkflowRequest) {
            return;
        }

        $transitions = $task->source->requestType?->allowed_status_transitions;
        if ($transitions === null) {
            return;
        }

        $allowedNextStatuses = $transitions[$task->status] ?? [];
        if (! in_array($newStatus, $allowedNextStatuses, true)) {
            throw new DomainRuleException(
                "この処理区分では [{$task->status}] から [{$newStatus}] への変更は許可されていません。"
            );
        }
    }
}
