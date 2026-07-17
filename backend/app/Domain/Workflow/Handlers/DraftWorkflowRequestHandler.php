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
use Illuminate\Support\Str;
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

        // 主キーがコマンド側生成のUUIDのため、workflow_requests行はここで直接作成せず
        // WorkflowRequestProjectorに委ねる(.claude/skills/add-projection参照)。
        $workflowRequestId = (string) Str::uuid();

        $this->eventStore->append(
            aggregateType: 'workflow_request',
            aggregateId: $workflowRequestId,
            event: new WorkflowRequestDrafted(
                workflowRequestId: $workflowRequestId,
                requestTypeId: $requestType->id,
                requestTypeCode: $requestType->code,
                applicantUserId: $command->applicantUserId,
                title: $command->title,
                formData: $command->formData,
                approverUserId: $command->approverUserId,
            ),
        );

        return WorkflowRequest::query()->findOrFail($workflowRequestId);
    }
}
