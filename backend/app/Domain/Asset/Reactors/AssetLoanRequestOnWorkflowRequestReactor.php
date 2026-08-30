<?php

namespace App\Domain\Asset\Reactors;

use App\Domain\Workflow\Events\WorkflowRequestApproved;
use App\Domain\Workflow\Events\WorkflowRequestCancelled;
use App\Domain\Workflow\Events\WorkflowRequestRejected;
use App\Domain\Workflow\Events\WorkflowRequestSubmitted;
use App\Models\AssetLoanRequest;
use App\Models\AssetLoanRequestStatus;
use App\Models\WorkflowRequest;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * 貸出申請(request_types.code=asset_loan)の workflow_requests側イベントを購読し、
 * asset_loan_requests(読み取り専用Projection)を更新する(spec 論点2)。
 * 備品ドメイン独自の申請イベントは持たない。行の新規作成は提出時(submitted)に行う
 * (下書きの段階では「申請」として扱わない)。
 */
class AssetLoanRequestOnWorkflowRequestReactor extends Reactor
{
    public function onWorkflowRequestSubmitted(WorkflowRequestSubmitted $event): void
    {
        $workflowRequest = WorkflowRequest::query()
            ->with('requestType')
            ->find($event->aggregateRootUuid());

        if ($workflowRequest === null || $workflowRequest->requestType?->code !== 'asset_loan') {
            return;
        }

        $formData = $workflowRequest->form_data ?? [];
        $assetId = $formData['asset_id'] ?? null;

        if ($assetId === null) {
            return;
        }

        AssetLoanRequest::query()->updateOrCreate(
            ['id' => $event->aggregateRootUuid()],
            [
                'asset_id' => $assetId,
                'applicant_user_id' => $workflowRequest->applicant_user_id,
                'approver_user_id' => $event->approverUserId,
                'status' => AssetLoanRequestStatus::PENDING,
                'purpose' => $formData['purpose'] ?? null,
                'submitted_at' => $event->createdAt(),
            ],
        );
    }

    public function onWorkflowRequestApproved(WorkflowRequestApproved $event): void
    {
        $loanRequest = AssetLoanRequest::query()->find($event->aggregateRootUuid());
        if ($loanRequest === null) {
            return;
        }

        $loanRequest->update([
            'status' => AssetLoanRequestStatus::APPROVED,
            'approved_at' => $event->createdAt(),
        ]);
    }

    public function onWorkflowRequestRejected(WorkflowRequestRejected $event): void
    {
        $loanRequest = AssetLoanRequest::query()->find($event->aggregateRootUuid());
        if ($loanRequest === null) {
            return;
        }

        $loanRequest->update([
            'status' => AssetLoanRequestStatus::REJECTED,
            'rejected_at' => $event->createdAt(),
            'rejection_reason' => $event->reason,
        ]);
    }

    /**
     * pending→withdrawn(承認前に申請者が取消)、approved→cancelled(承認済み取消)を
     * 取消前のstatusから判定する(spec「状態遷移」)。
     */
    public function onWorkflowRequestCancelled(WorkflowRequestCancelled $event): void
    {
        $loanRequest = AssetLoanRequest::query()->find($event->aggregateRootUuid());
        if ($loanRequest === null) {
            return;
        }

        if ($loanRequest->status === AssetLoanRequestStatus::PENDING) {
            $loanRequest->update([
                'status' => AssetLoanRequestStatus::WITHDRAWN,
                'withdrawn_at' => $event->createdAt(),
            ]);

            return;
        }

        if ($loanRequest->status === AssetLoanRequestStatus::APPROVED) {
            $loanRequest->update([
                'status' => AssetLoanRequestStatus::CANCELLED,
                'cancelled_at' => $event->createdAt(),
            ]);
        }
    }
}
