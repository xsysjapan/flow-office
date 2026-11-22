<?php

namespace App\Domain\BackOffice\Handlers;

use App\Domain\BackOffice\Aggregates\BackOfficeTaskAggregate;
use App\Domain\BackOffice\Commands\ChangeBackOfficeTaskStatus;
use App\Domain\BackOffice\Support\BackOfficeTaskTransitionPolicy;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\BackOfficeTask;
use App\Models\BackOfficeTaskStatus;
use InvalidArgumentException;

/**
 * UC-B003: 処理ステータスを更新する。
 *
 * @implements CommandHandler<ChangeBackOfficeTaskStatus>
 */
class ChangeBackOfficeTaskStatusHandler implements CommandHandler
{
    public function handle(Command $command): BackOfficeTask
    {
        assert($command instanceof ChangeBackOfficeTaskStatus);

        if (! in_array($command->newStatus, BackOfficeTaskStatus::all(), true)) {
            throw new InvalidArgumentException("不正なステータス [{$command->newStatus}] です。");
        }

        // sourceはWorkflowRequest以外(ExpenseClaim等、request_typeを持たないモデル)にも
        // morphする。'source.requestType'を無条件にeager loadするとWorkflowRequest以外の
        // 発生源で「未定義のリレーション」エラーになるため、requestTypeは下のinstanceof判定後に
        // WorkflowRequestに対してのみ遅延ロードする。
        $task = BackOfficeTask::query()->with('source')->findOrFail($command->backOfficeTaskId);
        $this->assertAllowedTransition($task, $command->newStatus);

        $previousStatus = $task->status;
        $aggregate = BackOfficeTaskAggregate::retrieve($task->id);

        if ($command->newStatus === BackOfficeTaskStatus::COMPLETED) {
            $aggregate->complete($command->changedByUserId, $command->comment)->persist();
        } else {
            $aggregate->changeStatus($previousStatus, $command->newStatus, $command->changedByUserId, $command->comment)->persist();
        }

        return $task->refresh();
    }

    /**
     * 遷移の許可判定は BackOfficeTaskTransitionPolicy に置く(担当者設定の確認中への変更と共有する)。
     */
    private function assertAllowedTransition(BackOfficeTask $task, string $newStatus): void
    {
        if (! (new BackOfficeTaskTransitionPolicy)->isAllowed($task, $newStatus)) {
            throw new DomainRuleException(
                "この処理区分では [{$task->status}] から [{$newStatus}] への変更は許可されていません。"
            );
        }
    }
}
