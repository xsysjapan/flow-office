<?php

namespace App\Domain\Workflow\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\Workflow\Commands\DraftWorkflowRequest;
use App\Domain\Workflow\Events\WorkflowRequestDrafted;
use App\Models\RequestType;
use App\Models\User;
use App\Models\WorkflowRequest;
use App\Models\WorkflowRequestStatus;
use InvalidArgumentException;

/**
 * @implements CommandHandler<DraftWorkflowRequest>
 */
class DraftWorkflowRequestHandler implements CommandHandler
{
    public function __construct(private readonly EventStore $eventStore) {}

    public function handle(Command $command): WorkflowRequest
    {
        assert($command instanceof DraftWorkflowRequest);

        $requestType = RequestType::query()
            ->where('code', $command->requestTypeCode)
            ->where('is_active', true)
            ->first();

        if ($requestType === null) {
            throw new InvalidArgumentException("申請種別 [{$command->requestTypeCode}] は存在しないか無効です。");
        }

        $applicantRoleCodes = User::query()->findOrFail($command->applicantUserId)
            ->roles()->pluck('code')->all();

        if (! $requestType->isEligibleForRoles($applicantRoleCodes)) {
            throw new DomainRuleException("申請種別 [{$requestType->name}] を申請する権限がありません。");
        }

        $workflowRequest = WorkflowRequest::query()->create([
            'request_type_id' => $requestType->id,
            'title' => $command->title,
            'applicant_user_id' => $command->applicantUserId,
            'approver_user_id' => $command->approverUserId,
            'status' => WorkflowRequestStatus::DRAFT,
            'form_data' => $command->formData,
        ]);

        $this->eventStore->append(
            aggregateType: 'workflow_request',
            aggregateId: (string) $workflowRequest->id,
            event: new WorkflowRequestDrafted(
                workflowRequestId: $workflowRequest->id,
                requestTypeCode: $requestType->code,
                applicantUserId: $command->applicantUserId,
                title: $command->title,
            ),
        );

        return $workflowRequest;
    }
}
