<?php

namespace App\Domain\Workflow\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\Workflow\Aggregates\WorkflowRequestAggregate;
use App\Domain\Workflow\Commands\SubmitWorkflowRequest;
use App\Domain\Workflow\Support\WorkflowRequestNotificationContent;
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

        // subject_type付きの行(月次勤怠・経費精算)は申請種別マスタを持たない(requestTypeがnull)
        // ため、申請種別由来の必須チェックは行わない。承認者の必須チェックは種別を問わず行う。
        if ($workflowRequest->requestType?->requires_attachment && ! $workflowRequest->attachments()->exists()) {
            throw new DomainRuleException('この申請種別は添付ファイルが必須です。');
        }

        $approverUserId = $command->approverUserId ?? $workflowRequest->approver_user_id;
        if ($approverUserId === null) {
            throw ValidationException::withMessages(['approver_user_id' => ['承認者を指定してください。']]);
        }

        WorkflowRequestAggregate::retrieve($workflowRequest->id)
            ->submit(approverUserId: $approverUserId, submittedByUserId: $command->submittedByUserId)
            ->persist();

        $workflowRequest->refresh();

        $approver = User::find($approverUserId);
        if ($approver !== null) {
            $content = WorkflowRequestNotificationContent::forSubmitted($workflowRequest);

            SendNotificationJob::enqueue(
                recipient: $approver,
                title: $content->title,
                summary: $content->summary,
                detailUrl: $content->detailUrl,
            );
        }

        return $workflowRequest;
    }
}
