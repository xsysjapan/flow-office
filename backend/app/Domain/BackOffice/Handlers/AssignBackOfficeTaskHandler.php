<?php

namespace App\Domain\BackOffice\Handlers;

use App\Domain\BackOffice\Commands\AssignBackOfficeTask;
use App\Domain\BackOffice\Events\BackOfficeTaskAssigned;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Jobs\SendNotificationJob;
use App\Models\BackOfficeTask;
use App\Models\User;

/**
 * UC-B002: 担当者を割り当てる。
 *
 * @implements CommandHandler<AssignBackOfficeTask>
 */
class AssignBackOfficeTaskHandler implements CommandHandler
{
    public function __construct(private readonly EventStore $eventStore) {}

    public function handle(Command $command): BackOfficeTask
    {
        assert($command instanceof AssignBackOfficeTask);

        $task = BackOfficeTask::query()->findOrFail($command->backOfficeTaskId);

        $this->eventStore->append(
            aggregateType: 'backoffice_task',
            aggregateId: (string) $task->id,
            event: new BackOfficeTaskAssigned(
                backOfficeTaskId: $task->id,
                assignedUserId: $command->assignedUserId,
                assignedByUserId: $command->assignedByUserId,
            ),
        );

        $task->refresh();

        $assignee = User::find($command->assignedUserId);
        if ($assignee !== null) {
            SendNotificationJob::enqueue(
                recipient: $assignee,
                title: 'タスク割当',
                summary: "「{$task->title}」が割り当てられました。",
                detailUrl: null,
            );
        }

        return $task;
    }
}
