<?php

namespace App\Domain\Workflow\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\Workflow\Aggregates\WorkflowRequestAggregate;
use App\Domain\Workflow\Commands\ApproveWorkflowRequest;
use App\Domain\Workflow\Support\WorkflowRequestNotificationContent;
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

        // approvedByUserIdがnullの場合は経費精算等のapproval_skip_thresholdによる自動承認
        // (Reactor経由のシステム操作)であり、承認者本人によるリクエストではないためチェックを
        // スキップする。
        if ($command->approvedByUserId !== null && $workflowRequest->approver_user_id !== $command->approvedByUserId) {
            throw new DomainRuleException('指定された承認者のみ承認できます。');
        }

        // このイベントを App\Domain\Workflow\Reactors\CreateBackOfficeTaskOnApprovalReactor が
        // 購読し、必要な申請種別ならバックオフィスタスクを自動生成する (UC-B001)。
        WorkflowRequestAggregate::retrieve($workflowRequest->id)
            ->approve($command->approvedByUserId)
            ->persist();

        $workflowRequest->refresh();

        $applicant = User::find($workflowRequest->applicant_user_id);
        if ($applicant !== null) {
            $content = WorkflowRequestNotificationContent::forApproved($workflowRequest);

            SendNotificationJob::enqueue(
                recipient: $applicant,
                title: $content->title,
                summary: $content->summary,
                detailUrl: $content->detailUrl,
            );
        }

        return $workflowRequest;
    }
}
