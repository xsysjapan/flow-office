<?php

namespace App\Domain\Workflow\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\Workflow\Aggregates\WorkflowRequestAggregate;
use App\Domain\Workflow\Commands\ApproveWorkflowRequest;
use App\Jobs\SendNotificationJob;
use App\Models\User;
use App\Models\WorkflowRequest;
use App\Models\WorkflowRequestStatus;

/**
 * @implements CommandHandler<ApproveWorkflowRequest>
 */
class ApproveWorkflowRequestHandler implements CommandHandler
{
    public function handle(Command $command): WorkflowRequest
    {
        assert($command instanceof ApproveWorkflowRequest);

        $workflowRequest = WorkflowRequest::query()->findOrFail($command->workflowRequestId);

        if ($workflowRequest->status !== WorkflowRequestStatus::SUBMITTED) {
            throw new DomainRuleException('提出済みの申請のみ承認できます。');
        }

        if ($workflowRequest->approver_user_id !== $command->approvedByUserId) {
            throw new DomainRuleException('指定された承認者のみ承認できます。');
        }

        // このイベントを App\Domain\Workflow\Reactors\CreateBackOfficeTaskOnApprovalReactor が
        // 購読し、必要な申請種別ならバックオフィスタスクを自動生成する (UC-B001)。
        WorkflowRequestAggregate::retrieve($workflowRequest->id)
            ->approve($command->approvedByUserId)
            ->persist();

        $applicant = User::find($workflowRequest->applicant_user_id);
        if ($applicant !== null) {
            SendNotificationJob::enqueue(
                recipient: $applicant,
                title: '承認完了',
                summary: "「{$workflowRequest->title}」が承認されました。",
                detailUrl: null,
            );
        }

        return $workflowRequest->refresh();
    }
}
