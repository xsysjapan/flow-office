<?php

namespace App\Domain\BackOffice\Handlers;

use App\Domain\BackOffice\Commands\AssignBackOfficeTask;
use App\Domain\BackOffice\Commands\AssignBackOfficeTasks;
use App\Domain\EventSourcing\BulkItemRunner;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Models\BackOfficeTask;

/**
 * タスクの担当者の一括変更。各件は`AssignBackOfficeTask`(1件の担当変更と同じ検査・記録・通知)を
 * dispatchして行い、1件でも失敗したら全体を戻す。
 *
 * @implements CommandHandler<AssignBackOfficeTasks>
 */
class AssignBackOfficeTasksHandler implements CommandHandler
{
    public function __construct(private readonly CommandBus $commandBus) {}

    /**
     * @return array{processed: list<int|string>, skipped: list<int|string>}
     */
    public function handle(Command $command): array
    {
        assert($command instanceof AssignBackOfficeTasks);

        $titles = BackOfficeTask::query()
            ->whereIn('id', $command->backOfficeTaskIds)
            ->pluck('title', 'id');

        return BulkItemRunner::run($command->backOfficeTaskIds, function (string $id) use ($command): void {
            $this->commandBus->dispatch(new AssignBackOfficeTask(
                backOfficeTaskId: $id,
                assignedUserId: $command->assignedUserId,
                assignedByUserId: $command->assignedByUserId,
            ));
        }, fn (string $id): ?string => $titles->get($id));
    }
}
