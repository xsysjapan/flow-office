<?php

namespace App\Domain\Workflow\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\Workflow\Commands\CancelWorkflowRequest;
use App\Domain\Workflow\Events\WorkflowRequestCancelled;
use App\Jobs\SendTeamsNotificationJob;
use App\Models\WorkflowRequest;
use App\Models\WorkflowRequestStatus;

/**
 * @implements CommandHandler<CancelWorkflowRequest>
 */
class CancelWorkflowRequestHandler implements CommandHandler
{
    public function __construct(private readonly EventStore $eventStore) {}

    public function handle(Command $command): WorkflowRequest
    {
        assert($command instanceof CancelWorkflowRequest);

        $workflowRequest = WorkflowRequest::query()->findOrFail($command->workflowRequestId);

        if ($workflowRequest->applicant_user_id !== $command->cancelledByUserId) {
            throw new DomainRuleException('自分が作成した申請のみ取り消せます。');
        }

        if (! in_array($workflowRequest->status, WorkflowRequestStatus::cancellable(), true)) {
            throw new DomainRuleException('この申請は現在のステータスからは取り消せません。');
        }

        $this->eventStore->append(
            aggregateType: 'workflow_request',
            aggregateId: (string) $workflowRequest->id,
            event: new WorkflowRequestCancelled(
                workflowRequestId: $workflowRequest->id,
                cancelledByUserId: $command->cancelledByUserId,
                reason: $command->reason,
            ),
        );

        if ($workflowRequest->approver_user_id !== null) {
            SendTeamsNotificationJob::enqueue(
                title: '申請取消',
                summary: "「{$workflowRequest->title}」が取り消されました: {$command->reason}",
                detailUrl: null,
            );
        }

        return $workflowRequest->refresh();
    }
}
