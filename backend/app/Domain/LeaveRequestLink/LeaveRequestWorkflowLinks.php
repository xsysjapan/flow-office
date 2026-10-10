<?php

namespace App\Domain\LeaveRequestLink;

use App\Models\LeaveRequestWorkflowLink;

/**
 * ワークフローID→休暇申請の対応表(leave_request_workflow_links)への問い合わせ。
 * 休暇申請文脈のReactorは、ワークフローのイベントからこの表だけで申請を特定する
 * (workflow_requestsを読まない)。
 */
final class LeaveRequestWorkflowLinks
{
    /**
     * @return array{leave_kind: string, leave_request_id: string}|null
     */
    public function find(string $workflowRequestId): ?array
    {
        $link = LeaveRequestWorkflowLink::query()->find($workflowRequestId);

        if ($link === null) {
            return null;
        }

        return [
            'leave_kind' => $link->leave_kind,
            'leave_request_id' => $link->leave_request_id,
        ];
    }

    public function workflowRequestIdFor(string $leaveKind, string $leaveRequestId): ?string
    {
        $workflowRequestId = LeaveRequestWorkflowLink::query()
            ->where('leave_kind', $leaveKind)
            ->where('leave_request_id', $leaveRequestId)
            ->value('workflow_request_id');

        return $workflowRequestId === null ? null : (string) $workflowRequestId;
    }
}
