<?php

namespace App\Domain\Workflow\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\Workflow\Aggregates\WorkflowRequestAggregate;
use App\Domain\Workflow\Commands\RejectWorkflowRequest;
use App\Jobs\SendNotificationJob;
use App\Models\User;
use App\Models\WorkflowRequest;
use App\Models\WorkflowRequestStatus;
use App\Support\FrontendUrl;

/**
 * @implements CommandHandler<RejectWorkflowRequest>
 */
class RejectWorkflowRequestHandler implements CommandHandler
{
    public function handle(Command $command): WorkflowRequest
    {
        assert($command instanceof RejectWorkflowRequest);

        $workflowRequest = WorkflowRequest::query()->findOrFail($command->workflowRequestId);

        if ($workflowRequest->status !== WorkflowRequestStatus::SUBMITTED) {
            throw new DomainRuleException('提出済みの申請のみ却下できます。');
        }

        if ($workflowRequest->approver_user_id !== $command->rejectedByUserId) {
            throw new DomainRuleException('指定された承認者のみ却下できます。');
        }

        WorkflowRequestAggregate::retrieve($workflowRequest->id)
            ->reject($command->rejectedByUserId, $command->reason)
            ->persist();

        $workflowRequest->refresh();

        $applicant = User::find($workflowRequest->applicant_user_id);
        if ($applicant !== null) {
            SendNotificationJob::enqueue(
                recipient: $applicant,
                title: '申請却下',
                summary: "「{$workflowRequest->title}」が却下されました: {$command->reason}",
                detailUrl: FrontendUrl::path("/requests/{$workflowRequest->id}"),
            );
        }

        return $workflowRequest;
    }
}
