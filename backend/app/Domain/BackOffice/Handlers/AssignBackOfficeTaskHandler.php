<?php

namespace App\Domain\BackOffice\Handlers;

use App\Domain\BackOffice\Aggregates\BackOfficeTaskAggregate;
use App\Domain\BackOffice\Commands\AssignBackOfficeTask;
use App\Domain\BackOffice\Support\BackOfficeTaskAssignmentDecision;
use App\Domain\BackOffice\Support\BackOfficeTaskTransitionPolicy;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Jobs\SendNotificationJob;
use App\Models\BackOfficeTask;
use App\Models\BackOfficeTaskStatus;
use App\Models\User;

/**
 * UC-B002: 担当者を割り当てる(未割当・割当済みのどちらも。担当者の変更を含む)。
 *
 * 担当者の設定は backoffice_task.assigned、未着手からの確認中への変化は backoffice_task.status_changed
 * として同じ集約に記録する。確認中への変化は遷移マスタが許可する場合のみ行う。
 *
 * @implements CommandHandler<AssignBackOfficeTask>
 */
class AssignBackOfficeTaskHandler implements CommandHandler
{
    public function handle(Command $command): BackOfficeTask
    {
        assert($command instanceof AssignBackOfficeTask);

        // sourceは遷移の許可判定(BackOfficeTaskTransitionPolicy)で使うため読み込む。
        $task = BackOfficeTask::query()->with('source')->findOrFail($command->backOfficeTaskId);

        $decision = new BackOfficeTaskAssignmentDecision;
        if ($decision->decide($task->assigned_user_id, $task->status, $command->assignedUserId) === BackOfficeTaskAssignmentDecision::NOOP) {
            return $task;
        }

        $previousAssignedUserId = $task->assigned_user_id;
        $transitionAllowed = (new BackOfficeTaskTransitionPolicy)->isAllowed($task, BackOfficeTaskStatus::IN_REVIEW);

        $aggregate = BackOfficeTaskAggregate::retrieve($task->id)
            ->assign($command->assignedUserId, $command->assignedByUserId, $previousAssignedUserId);

        if ($decision->shouldMoveToInReview($task->status, $transitionAllowed)) {
            $aggregate->changeStatus(
                BackOfficeTaskStatus::NOT_STARTED,
                BackOfficeTaskStatus::IN_REVIEW,
                $command->assignedByUserId,
                null,
            );
        }

        $aggregate->persist();

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

        // 前の担当者が手を離れたことを知らせる(操作者本人が外した場合は送らない)。
        $previousAssignee = $previousAssignedUserId !== null ? User::find($previousAssignedUserId) : null;
        if ($previousAssignee !== null && $previousAssignedUserId !== $command->assignedByUserId) {
            $assigneeName = $assignee?->name;
            $assigneeLabel = $assigneeName !== null ? "{$assigneeName}さん" : '別の担当者';
            SendNotificationJob::enqueue(
                recipient: $previousAssignee,
                title: 'タスク担当変更',
                summary: "「{$task->title}」の担当が{$assigneeLabel}に変更されました。",
                detailUrl: null,
            );
        }

        return $task;
    }
}
