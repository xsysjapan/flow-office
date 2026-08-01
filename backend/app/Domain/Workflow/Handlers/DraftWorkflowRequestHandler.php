<?php

namespace App\Domain\Workflow\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\Workflow\Aggregates\WorkflowRequestAggregate;
use App\Domain\Workflow\Commands\DraftWorkflowRequest;
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
    public function handle(Command $command): WorkflowRequest
    {
        assert($command instanceof DraftWorkflowRequest);

        // subject指定(月次勤怠・経費精算)の場合は申請種別マスタを持たないため、
        // RequestTypeの検索も申請権限チェックも行わない。title・applicantUserId・
        // approverUserIdは呼び出し元(対象ドメイン側のReactor)が用意した値をそのまま使う。
        $requestType = $command->subjectType !== null
            ? null
            : $this->resolveRequestType($command);

        $workflowRequestId = (string) Str::uuid();

        WorkflowRequestAggregate::retrieve($workflowRequestId)
            ->draft(
                requestTypeId: $requestType?->id,
                requestTypeCode: $requestType?->code,
                applicantUserId: $command->applicantUserId,
                title: $command->title,
                formData: $command->formData,
                approverUserId: $command->approverUserId,
                subjectType: $command->subjectType,
                subjectId: $command->subjectId,
            )
            ->persist();

        return WorkflowRequest::query()->findOrFail($workflowRequestId);
    }

    private function resolveRequestType(DraftWorkflowRequest $command): RequestType
    {
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

        return $requestType;
    }
}
