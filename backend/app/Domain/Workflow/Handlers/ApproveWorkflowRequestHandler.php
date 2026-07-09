<?php

namespace App\Domain\Workflow\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\Workflow\Commands\ApproveWorkflowRequest;
use App\Domain\Workflow\Events\WorkflowRequestApproved;
use App\Jobs\SendTeamsNotificationJob;
use App\Models\WorkflowRequest;
use App\Models\WorkflowRequestStatus;
use Illuminate\Support\Carbon;

/**
 * @implements CommandHandler<ApproveWorkflowRequest>
 */
class ApproveWorkflowRequestHandler implements CommandHandler
{
    public function __construct(private readonly EventStore $eventStore) {}

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

        $workflowRequest->status = WorkflowRequestStatus::APPROVED;
        $workflowRequest->approved_at = Carbon::now();
        $workflowRequest->save();

        // このイベントを BackOfficeTaskAutoCreationProjector が購読し、
        // 必要な申請種別ならバックオフィスタスクを自動生成する (UC-B001)。
        $this->eventStore->append(
            aggregateType: 'workflow_request',
            aggregateId: (string) $workflowRequest->id,
            event: new WorkflowRequestApproved(
                workflowRequestId: $workflowRequest->id,
                approvedByUserId: $command->approvedByUserId,
            ),
        );

        SendTeamsNotificationJob::dispatch(
            title: '承認完了',
            summary: "「{$workflowRequest->title}」が承認されました。",
            detailUrl: null,
        );

        return $workflowRequest;
    }
}
