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

    /**
     * 休暇申請(モデルの配列・コレクション)に対応するワークフローIDを、非永続の属性
     * `workflow_request_id`として付ける(対応が無ければnull)。一覧はまとめて1回の問い合わせで
     * 引く(N+1を避ける)。ワークフローのテーブルは読まない。
     *
     * @param  iterable<int, \Illuminate\Database\Eloquent\Model>  $leaveRequests
     */
    public function attachWorkflowRequestIds(string $leaveKind, iterable $leaveRequests): void
    {
        $requests = is_array($leaveRequests) ? $leaveRequests : iterator_to_array($leaveRequests, false);

        if ($requests === []) {
            return;
        }

        $workflowRequestIdByLeaveRequestId = LeaveRequestWorkflowLink::query()
            ->where('leave_kind', $leaveKind)
            ->whereIn('leave_request_id', array_map(fn ($request) => $request->getKey(), $requests))
            ->pluck('workflow_request_id', 'leave_request_id');

        foreach ($requests as $request) {
            $workflowRequestId = $workflowRequestIdByLeaveRequestId[$request->getKey()] ?? null;
            $request->setAttribute('workflow_request_id', $workflowRequestId === null ? null : (string) $workflowRequestId);
        }
    }
}
