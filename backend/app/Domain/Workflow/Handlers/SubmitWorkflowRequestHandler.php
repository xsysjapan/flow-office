<?php

namespace App\Domain\Workflow\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\Workflow\Commands\SubmitWorkflowRequest;
use App\Domain\Workflow\Events\WorkflowRequestSubmitted;
use App\Jobs\SendNotificationJob;
use App\Models\User;
use App\Models\WorkflowRequest;
use App\Models\WorkflowRequestStatus;
use Illuminate\Validation\ValidationException;

/**
 * @implements CommandHandler<SubmitWorkflowRequest>
 */
class SubmitWorkflowRequestHandler implements CommandHandler
{
    public function __construct(private readonly EventStore $eventStore) {}

    public function handle(Command $command): WorkflowRequest
    {
        assert($command instanceof SubmitWorkflowRequest);

        $workflowRequest = WorkflowRequest::query()->with('requestType')->findOrFail($command->workflowRequestId);

        if ($workflowRequest->applicant_user_id !== $command->submittedByUserId) {
            throw new DomainRuleException('自分が作成した申請のみ申請できます。');
        }

        if (! in_array($workflowRequest->status, [WorkflowRequestStatus::DRAFT, WorkflowRequestStatus::RETURNED], true)) {
            throw new DomainRuleException('この申請は現在のステータスからは提出できません。');
        }

        if ($workflowRequest->requestType->requires_attachment && ! $workflowRequest->attachments()->exists()) {
            throw new DomainRuleException('この申請種別は添付ファイルが必須です。');
        }

        $approverUserId = $command->approverUserId ?? $workflowRequest->approver_user_id;
        if ($approverUserId === null) {
            throw ValidationException::withMessages(['approver_user_id' => ['承認者を指定してください。']]);
        }

        $this->eventStore->append(
            aggregateType: 'workflow_request',
            aggregateId: (string) $workflowRequest->id,
            event: new WorkflowRequestSubmitted(
                workflowRequestId: $workflowRequest->id,
                approverUserId: $approverUserId,
                submittedByUserId: $command->submittedByUserId,
            ),
        );

        $approver = User::find($approverUserId);
        if ($approver !== null) {
            SendNotificationJob::enqueue(
                recipient: $approver,
                title: '承認依頼',
                summary: "「{$workflowRequest->title}」の承認依頼が届いています。",
                detailUrl: null,
            );
        }

        return $workflowRequest->refresh();
    }
}
