<?php

namespace App\Domain\Workflow\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\Workflow\Aggregates\WorkflowRequestAggregate;
use App\Domain\Workflow\Commands\ReturnWorkflowRequest;
use App\Jobs\SendNotificationJob;
use App\Models\User;
use App\Models\WorkflowRequest;
use App\Models\WorkflowRequestStatus;

/**
 * @implements CommandHandler<ReturnWorkflowRequest>
 */
class ReturnWorkflowRequestHandler implements CommandHandler
{
    public function handle(Command $command): WorkflowRequest
    {
        assert($command instanceof ReturnWorkflowRequest);

        $workflowRequest = WorkflowRequest::query()->findOrFail($command->workflowRequestId);

        if ($workflowRequest->status !== WorkflowRequestStatus::SUBMITTED) {
            throw new DomainRuleException('提出済みの申請のみ差戻しできます。');
        }

        if ($workflowRequest->approver_user_id !== $command->returnedByUserId) {
            throw new DomainRuleException('指定された承認者のみ差戻しできます。');
        }

        WorkflowRequestAggregate::retrieve($workflowRequest->id)
            ->returnRequest($command->returnedByUserId, $command->comment)
            ->persist();

        $applicant = User::find($workflowRequest->applicant_user_id);
        if ($applicant !== null) {
            SendNotificationJob::enqueue(
                recipient: $applicant,
                title: '差戻し',
                summary: "「{$workflowRequest->title}」が差し戻されました: {$command->comment}",
                detailUrl: null,
            );
        }

        return $workflowRequest->refresh();
    }
}
