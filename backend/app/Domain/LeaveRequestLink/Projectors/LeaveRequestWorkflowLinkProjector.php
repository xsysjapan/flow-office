<?php

namespace App\Domain\LeaveRequestLink\Projectors;

use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequestShared;
use App\Domain\PaidLeave\Events\PaidLeaveRequestShared;
use App\Domain\PaidLeaveRequest\Events\PaidLeaveRequestLifecycleShared;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequestShared;
use App\Domain\Workflow\Events\WorkflowRequestDrafted;
use App\Models\LeaveRequestWorkflowLink;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * ワークフローID→休暇申請の対応表(leave_request_workflow_links)を作る。
 *
 * - workflow_request.drafted: subject_typeが休暇の3種のときだけ、集約ID(=ワークフローID)と
 *   subject_id(=休暇申請ID)を対応付ける。
 * - 休暇申請の*.shared(申請・承認文脈のワークフローを提出したことの記録): イベントのworkflowRequestIdと
 *   集約ID(=休暇申請ID)を対応付ける。
 *
 * 全てworkflow_request_idをキーにupsertする(冪等)。行の存在を前提としない。
 * 再生成は対応表を空にしてから event-sourcing:replay LeaveRequestWorkflowLinkProjector で行う
 * (spatieのreplayはテーブルを自動で空にしないため。docs/29-event-sourcing-framework-migration.md参照)。
 */
class LeaveRequestWorkflowLinkProjector extends Projector
{
    private const LEAVE_KIND_BY_SUBJECT_TYPE = [
        'paid_leave_request' => LeaveRequestWorkflowLink::KIND_PAID,
        'special_leave_request' => LeaveRequestWorkflowLink::KIND_SPECIAL,
        'compensatory_leave_request' => LeaveRequestWorkflowLink::KIND_COMPENSATORY,
    ];

    public function onWorkflowRequestDrafted(WorkflowRequestDrafted $event): void
    {
        $leaveKind = self::LEAVE_KIND_BY_SUBJECT_TYPE[$event->subjectType] ?? null;
        if ($leaveKind === null || $event->subjectId === null) {
            return;
        }

        $this->link($event->aggregateRootUuid(), $leaveKind, $event->subjectId);
    }

    /** 旧`paid_leave.request_shared`(本変更前のイベント。発行元は現行コードに無く、履歴の再生で使う)。 */
    public function onPaidLeaveRequestShared(PaidLeaveRequestShared $event): void
    {
        $this->link($event->workflowRequestId, LeaveRequestWorkflowLink::KIND_PAID, $event->aggregateRootUuid());
    }

    public function onPaidLeaveRequestLifecycleShared(PaidLeaveRequestLifecycleShared $event): void
    {
        $this->link($event->workflowRequestId, LeaveRequestWorkflowLink::KIND_PAID, $event->aggregateRootUuid());
    }

    public function onSpecialLeaveRequestShared(SpecialLeaveRequestShared $event): void
    {
        $this->link($event->workflowRequestId, LeaveRequestWorkflowLink::KIND_SPECIAL, $event->aggregateRootUuid());
    }

    public function onCompensatoryLeaveRequestShared(CompensatoryLeaveRequestShared $event): void
    {
        $this->link($event->workflowRequestId, LeaveRequestWorkflowLink::KIND_COMPENSATORY, $event->aggregateRootUuid());
    }

    private function link(string $workflowRequestId, string $leaveKind, string $leaveRequestId): void
    {
        LeaveRequestWorkflowLink::query()->updateOrCreate(
            ['workflow_request_id' => $workflowRequestId],
            ['leave_kind' => $leaveKind, 'leave_request_id' => $leaveRequestId],
        );
    }
}
