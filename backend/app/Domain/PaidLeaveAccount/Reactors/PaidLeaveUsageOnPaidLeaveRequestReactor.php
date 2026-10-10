<?php

namespace App\Domain\PaidLeaveAccount\Reactors;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\PaidLeaveAccount\Commands\CancelPaidLeaveUsage;
use App\Domain\PaidLeaveAccount\Commands\ConfirmPaidLeaveUsage;
use App\Domain\PaidLeaveAccount\Commands\DesignatePaidLeaveUsage;
use App\Domain\PaidLeaveRequest\Events\PaidLeaveRequestLifecycleApproved;
use App\Domain\PaidLeaveRequest\Events\PaidLeaveRequestLifecycleCancelled;
use App\Domain\PaidLeaveRequest\Events\PaidLeaveRequestLifecycleRequested;
use App\Domain\PaidLeaveRequest\Events\PaidLeaveRequestLifecycleResubmitted;
use App\Domain\PaidLeaveRequest\Events\PaidLeaveRequestLifecycleReturned;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * 有給申請の申請状態の変化(paid_leave_request.*)を受けて、有給口座(PaidLeaveAccountAggregate)の
 * 消化記録を作成・確定・取消する(残数・使用の文脈のReactor。原則15)。
 *
 * - requested / resubmitted → 消化記録の作成(再提出は新しい消化記録。論点8)
 * - approved → 消化記録の確定(残数不足でも確定し、充当できた分だけ充当。論点17)
 * - returned / cancelled → 消化記録の取消(承認済みは充当を解除し残高を戻す)
 *
 * 対象の消化記録は申請IDで特定する。migrated には反応しない(引き継ぎ時点の消化記録は運用コマンドが扱う)。
 * 全て viaReactor=true で発行し、既に目的の状態なら何もしない(冪等)。
 */
class PaidLeaveUsageOnPaidLeaveRequestReactor extends Reactor
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function onPaidLeaveRequestLifecycleRequested(PaidLeaveRequestLifecycleRequested $event): void
    {
        $this->designate(
            requestId: $event->aggregateRootUuid(),
            userId: $event->userId,
            workflowRequestId: $event->workflowRequestId,
            targetDate: $event->targetDate,
            leaveType: $event->leaveType,
            hours: $event->hours,
            requestedDays: $event->requestedDays,
            approverUserId: $event->approverUserId,
            reason: $event->reason,
            requestGroupId: $event->requestGroupId,
            initiatedByUserId: $event->userId,
        );
    }

    public function onPaidLeaveRequestLifecycleResubmitted(PaidLeaveRequestLifecycleResubmitted $event): void
    {
        if ($event->userId === null || $event->targetDate === null || $event->leaveType === null || $event->requestedDays === null) {
            // 集約は申請済みの内容を必ず記録するため、ここに来るのは不整合のみ。
            throw new \LogicException('再提出のイベントに申請内容がありません: '.$event->aggregateRootUuid());
        }

        $this->designate(
            requestId: $event->aggregateRootUuid(),
            userId: $event->userId,
            workflowRequestId: $event->workflowRequestId,
            targetDate: $event->targetDate,
            leaveType: $event->leaveType,
            hours: $event->hours,
            requestedDays: $event->requestedDays,
            approverUserId: $event->approverUserId,
            reason: $event->reason,
            requestGroupId: $event->requestGroupId,
            initiatedByUserId: $event->resubmittedByUserId,
        );
    }

    public function onPaidLeaveRequestLifecycleApproved(PaidLeaveRequestLifecycleApproved $event): void
    {
        if ($event->userId === null) {
            throw new \LogicException('承認のイベントに申請者がありません: '.$event->aggregateRootUuid());
        }

        $this->commandBus->dispatch(new ConfirmPaidLeaveUsage(
            userId: $event->userId,
            usageId: null,
            confirmedByUserId: $event->approvedByUserId,
            paidLeaveRequestId: $event->aggregateRootUuid(),
            viaReactor: true,
            initiatedByUserId: $event->approvedByUserId,
        ));
    }

    public function onPaidLeaveRequestLifecycleReturned(PaidLeaveRequestLifecycleReturned $event): void
    {
        if ($event->userId === null) {
            throw new \LogicException('差戻しのイベントに申請者がありません: '.$event->aggregateRootUuid());
        }

        $this->commandBus->dispatch(new CancelPaidLeaveUsage(
            userId: $event->userId,
            usageId: null,
            cancelledByUserId: $event->returnedByUserId,
            reason: '有給申請の差戻しによる消化記録の取消',
            paidLeaveRequestId: $event->aggregateRootUuid(),
            viaReactor: true,
            initiatedByUserId: $event->returnedByUserId,
        ));
    }

    public function onPaidLeaveRequestLifecycleCancelled(PaidLeaveRequestLifecycleCancelled $event): void
    {
        if ($event->userId === null) {
            throw new \LogicException('取消のイベントに申請者がありません: '.$event->aggregateRootUuid());
        }

        $this->commandBus->dispatch(new CancelPaidLeaveUsage(
            userId: $event->userId,
            usageId: null,
            cancelledByUserId: $event->cancelledByUserId,
            reason: $event->reason ?? '有給申請の取消',
            paidLeaveRequestId: $event->aggregateRootUuid(),
            viaReactor: true,
            initiatedByUserId: $event->cancelledByUserId,
        ));
    }

    private function designate(
        string $requestId,
        string $userId,
        ?string $workflowRequestId,
        string $targetDate,
        string $leaveType,
        ?float $hours,
        float $requestedDays,
        ?string $approverUserId,
        ?string $reason,
        ?string $requestGroupId,
        ?string $initiatedByUserId,
    ): void {
        $this->commandBus->dispatch(new DesignatePaidLeaveUsage(
            userId: $userId,
            workflowRequestId: $workflowRequestId,
            attendanceDayId: null,
            usedOn: $targetDate,
            usedDays: $requestedDays,
            usageType: $leaveType,
            paidLeaveRequestId: $requestId,
            approverUserId: $approverUserId,
            reason: $reason,
            requestGroupId: $requestGroupId,
            hours: $hours,
            viaReactor: true,
            initiatedByUserId: $initiatedByUserId,
        ));
    }
}
