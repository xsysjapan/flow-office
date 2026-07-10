<?php

namespace App\Domain\BackOffice\Handlers;

use App\Domain\BackOffice\Commands\ChangeBackOfficeTaskStatus;
use App\Domain\BackOffice\Events\BackOfficeTaskCompleted;
use App\Domain\BackOffice\Events\BackOfficeTaskStatusChanged;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Models\BackOfficeTask;
use App\Models\BackOfficeTaskStatus;
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

        $task = BackOfficeTask::query()->findOrFail($command->backOfficeTaskId);
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
}
