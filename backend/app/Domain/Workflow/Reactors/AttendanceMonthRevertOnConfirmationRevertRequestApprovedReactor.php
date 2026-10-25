<?php

namespace App\Domain\Workflow\Reactors;

use App\Domain\Attendance\Commands\RevertApprovedAttendanceMonthByRequest;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\Workflow\Events\WorkflowRequestApproved;
use App\Models\WorkflowRequest;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * 勤怠確定取消依頼(attendance_confirmation_revert)が承認されたら、申請者本人の月次勤怠の
 * 確定を即時に取り消す。同期Reactorのため、取り消せない場合(承認待ち・締め済み・月なし)の
 * DomainRuleExceptionで承認ごとロールバックされる。このReactorはworkflow_requestsだけを読み、
 * 対象の月の特定と状態の判定は勤怠側のHandlerが行う。
 */
class AttendanceMonthRevertOnConfirmationRevertRequestApprovedReactor extends Reactor
{
    public const REQUEST_TYPE_CODE = 'attendance_confirmation_revert';

    public function __construct(private readonly CommandBus $commandBus) {}

    public function onWorkflowRequestApproved(WorkflowRequestApproved $event): void
    {
        $workflowRequest = WorkflowRequest::query()->with('requestType')->find($event->aggregateRootUuid());

        if ($workflowRequest?->requestType?->code !== self::REQUEST_TYPE_CODE) {
            return;
        }

        $formData = $workflowRequest->form_data ?? [];

        $this->commandBus->dispatch(new RevertApprovedAttendanceMonthByRequest(
            userId: $workflowRequest->applicant_user_id,
            yearMonth: (string) ($formData['target_year_month'] ?? ''),
            reason: (string) ($formData['reason'] ?? ''),
            workflowRequestId: $workflowRequest->id,
            revertedByUserId: $event->approvedByUserId,
        ));
    }
}
