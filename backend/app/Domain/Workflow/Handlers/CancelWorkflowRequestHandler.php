<?php

namespace App\Domain\Workflow\Handlers;

use App\Domain\AccessControl\Services\EffectiveAccessResolver;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\Workflow\Aggregates\WorkflowRequestAggregate;
use App\Domain\Workflow\Commands\CancelWorkflowRequest;
use App\Jobs\SendNotificationJob;
use App\Models\User;
use App\Models\WorkflowRequest;
use App\Models\WorkflowRequestStatus;
use App\Support\FrontendUrl;

/**
 * @implements CommandHandler<CancelWorkflowRequest>
 */
class CancelWorkflowRequestHandler implements CommandHandler
{
    public function __construct(private readonly EffectiveAccessResolver $effectiveAccessResolver)
    {
    }

    public function handle(Command $command): WorkflowRequest
    {
        assert($command instanceof CancelWorkflowRequest);

        $workflowRequest = WorkflowRequest::query()->findOrFail($command->workflowRequestId);

        if ($workflowRequest->applicant_user_id !== $command->cancelledByUserId) {
            throw new DomainRuleException('自分が作成した申請のみ取り消せます。');
        }

        if ($workflowRequest->subject_type === 'attendance_month') {
            $cancelledBy = User::query()->findOrFail($command->cancelledByUserId);
            if (! $this->effectiveAccessResolver->hasPermission($cancelledBy, 'attendance.submission_revoke', null, $command->cancelledByUserId)) {
                throw new DomainRuleException('月次勤怠の取消には権限が必要です。');
            }
        }

        if (! in_array($workflowRequest->status, WorkflowRequestStatus::cancellable(), true)) {
            throw new DomainRuleException('この申請は現在のステータスからは取り消せません。');
        }

        WorkflowRequestAggregate::retrieve($workflowRequest->id)
            ->cancel($command->cancelledByUserId, $command->reason)
            ->persist();

        if ($workflowRequest->approver_user_id !== null) {
            $approver = User::find($workflowRequest->approver_user_id);
            if ($approver !== null) {
                SendNotificationJob::enqueue(
                    recipient: $approver,
                    title: '申請取消',
                    summary: "「{$workflowRequest->title}」が取り消されました: {$command->reason}",
                    detailUrl: FrontendUrl::path("/requests/{$workflowRequest->id}"),
                );
            }
        }

        return $workflowRequest->refresh();
    }
}
