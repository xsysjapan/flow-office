<?php

namespace App\Domain\BackOffice\Handlers;

use App\Domain\BackOffice\Commands\ChangeBackOfficeTaskStatus;
use App\Domain\BackOffice\Commands\CompleteBackOfficeTasks;
use App\Domain\EventSourcing\BulkItemRunner;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\BackOfficeTask;
use App\Models\BackOfficeTaskStatus;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * 自分が担当するタスクの一括完了。各件は`ChangeBackOfficeTaskStatus`(1件のステータス変更と同じ検査・記録)を
 * dispatchして行い、1件でも失敗したら全体を戻す。担当者本人のタスクだけを対象にする検査はここに置く
 * (1件のステータス変更のAPIには無い)。完了済みのタスクは成功として飛ばす(イベントを記録しない)。
 *
 * @implements CommandHandler<CompleteBackOfficeTasks>
 */
class CompleteBackOfficeTasksHandler implements CommandHandler
{
    public function __construct(private readonly CommandBus $commandBus) {}

    /**
     * @return array{processed: list<int|string>, skipped: list<int|string>}
     */
    public function handle(Command $command): array
    {
        assert($command instanceof CompleteBackOfficeTasks);

        $tasks = BackOfficeTask::query()
            ->whereIn('id', $command->backOfficeTaskIds)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        return BulkItemRunner::run($command->backOfficeTaskIds, function (string $id) use ($command, $tasks): bool {
            $task = $tasks->get($id);
            if ($task === null) {
                throw (new ModelNotFoundException)->setModel(BackOfficeTask::class, [$id]);
            }

            if ($task->assigned_user_id !== $command->completedByUserId) {
                throw new DomainRuleException('自分に割り当てられていないタスクは一括完了できません。');
            }

            if ($task->status === BackOfficeTaskStatus::COMPLETED) {
                return false;
            }

            $this->commandBus->dispatch(new ChangeBackOfficeTaskStatus(
                backOfficeTaskId: $id,
                newStatus: BackOfficeTaskStatus::COMPLETED,
                changedByUserId: $command->completedByUserId,
                comment: $command->comment,
            ));

            return true;
        }, fn (string $id): ?string => $tasks->get($id)?->title);
    }
}
