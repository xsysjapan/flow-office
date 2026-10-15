<?php

namespace App\Domain\CompensatoryLeave\Reactors;

use App\Domain\CompensatoryLeave\Commands\ApproveCompensatoryLeaveRequest;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\LeaveRequestLink\LeaveRequestWorkflowLinks;
use App\Domain\Workflow\Events\WorkflowRequestApproved;
use App\Models\CompensatoryLeaveRequest;
use App\Models\CompensatoryLeaveRequestStatus;
use App\Models\LeaveRequestWorkflowLink;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * ワークフローの承認(workflow_request.approved)を受けて、対応する代休申請を承認する(休暇申請文脈のReactor)。
 *
 * まとめ申請(同じ request_group_id)の兄弟のうち、まだ申請中のものも承認する(論点5。差戻し中・取消済み・
 * 承認済みは対象外)。兄弟の承認は`compensatory_leave.request_approved`として記録され、申請・承認文脈の
 * Reactorが兄弟のワークフローを承認する(逆方向の連携)。兄弟の特定は自文脈の申請テーブルで行う。
 */
class CompensatoryLeaveApprovalOnWorkflowRequestReactor extends Reactor
{
    public function __construct(
        private readonly CommandBus $commandBus,
        private readonly LeaveRequestWorkflowLinks $links,
    ) {}

    public function onWorkflowRequestApproved(WorkflowRequestApproved $event): void
    {
        $link = $this->links->find($event->aggregateRootUuid());
        if ($link === null || $link['leave_kind'] !== LeaveRequestWorkflowLink::KIND_COMPENSATORY) {
            return;
        }

        $compensatoryLeaveRequestId = $link['leave_request_id'];

        $this->commandBus->dispatch(new ApproveCompensatoryLeaveRequest(
            compensatoryLeaveRequestId: $compensatoryLeaveRequestId,
            approvedByUserId: $event->approvedByUserId,
            viaReactor: true,
            initiatedByUserId: $event->approvedByUserId,
        ));

        $this->approveGroupSiblings($compensatoryLeaveRequestId, $event->approvedByUserId);
    }

    /**
     * 同じ request_group_id を持ち、まだ申請中(submitted)の他の申請を承認する。1件ずつ再度DBを見ながら
     * 処理する(再帰的に兄弟が承認されても二重承認にならないようにする)。
     */
    private function approveGroupSiblings(string $compensatoryLeaveRequestId, ?string $approvedByUserId): void
    {
        $request = CompensatoryLeaveRequest::query()->find($compensatoryLeaveRequestId);

        if ($request === null || $request->request_group_id === null) {
            return;
        }

        // 処理済みの兄弟は除外する(承認に失敗せず状態が変わらない場合でも無限ループにしないため)。
        $handled = [$request->id];

        while (true) {
            $sibling = CompensatoryLeaveRequest::query()
                ->where('request_group_id', $request->request_group_id)
                ->whereNotIn('id', $handled)
                ->where('status', CompensatoryLeaveRequestStatus::SUBMITTED)
                ->orderBy('target_date')
                ->first();

            if ($sibling === null) {
                return;
            }

            $handled[] = $sibling->id;

            $this->commandBus->dispatch(new ApproveCompensatoryLeaveRequest(
                compensatoryLeaveRequestId: $sibling->id,
                approvedByUserId: $approvedByUserId,
                viaReactor: true,
                initiatedByUserId: $approvedByUserId,
            ));
        }
    }
}
