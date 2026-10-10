<?php

namespace App\Domain\SpecialLeaveAccount\Reactors;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequestApproved;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequestCancelled;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequested;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequestResubmitted;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequestReturned;
use App\Domain\SpecialLeaveAccount\Commands\CancelSpecialLeaveUsage;
use App\Domain\SpecialLeaveAccount\Commands\ConfirmSpecialLeaveUsage;
use App\Domain\SpecialLeaveAccount\Commands\DesignateSpecialLeaveUsage;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * 特別休暇申請の申請状態の変化(special_leave.request_*)を受けて、特別休暇口座(SpecialLeaveAccountAggregate)の
 * 消化記録を作成・確定・取消する(残数・使用の文脈のReactor。原則15)。
 *
 * - requested / resubmitted → 消化記録の作成(再提出は新しい消化記録。論点8)
 * - approved → 消化記録の確定(残数不足でも確定し、充当できた分だけ充当。論点17)
 * - returned / cancelled → 消化記録の取消(承認済みは充当を解除し残高を戻す)
 *
 * 対象の消化記録は申請IDで特定する。全て viaReactor=true で発行し、既に目的の状態なら何もしない(冪等)。
 */
class SpecialLeaveUsageOnSpecialLeaveRequestReactor extends Reactor
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function onSpecialLeaveRequested(SpecialLeaveRequested $event): void
    {
        $this->designate(
            requestId: $event->aggregateRootUuid(),
            userId: $event->userId,
            specialLeaveTypeId: $event->specialLeaveTypeId,
            targetDate: $event->targetDate,
            leaveType: $event->leaveType,
            hours: $event->hours,
            requestedDays: $event->requestedDays,
            initiatedByUserId: $event->userId,
        );
    }

    public function onSpecialLeaveRequestResubmitted(SpecialLeaveRequestResubmitted $event): void
    {
        if ($event->userId === null
            || $event->specialLeaveTypeId === null
            || $event->targetDate === null
            || $event->leaveType === null
            || $event->requestedDays === null
        ) {
            // 集約は申請済みの内容を必ず記録するため、ここに来るのは不整合のみ。
            throw new \LogicException('再提出のイベントに申請内容がありません: '.$event->aggregateRootUuid());
        }

        $this->designate(
            requestId: $event->aggregateRootUuid(),
            userId: $event->userId,
            specialLeaveTypeId: $event->specialLeaveTypeId,
            targetDate: $event->targetDate,
            leaveType: $event->leaveType,
            hours: $event->hours,
            requestedDays: $event->requestedDays,
            initiatedByUserId: $event->resubmittedByUserId ?? $event->userId,
        );
    }

    public function onSpecialLeaveRequestApproved(SpecialLeaveRequestApproved $event): void
    {
        if ($event->userId === null || $event->requiresGrant === null) {
            throw new \LogicException('承認のイベントに申請者・残数の要否がありません: '.$event->aggregateRootUuid());
        }

        $this->commandBus->dispatch(new ConfirmSpecialLeaveUsage(
            userId: $event->userId,
            requestId: $event->aggregateRootUuid(),
            requiresGrant: $event->requiresGrant,
            viaReactor: true,
            initiatedByUserId: $event->approvedByUserId,
        ));
    }

    public function onSpecialLeaveRequestReturned(SpecialLeaveRequestReturned $event): void
    {
        if ($event->userId === null) {
            throw new \LogicException('差戻しのイベントに申請者がありません: '.$event->aggregateRootUuid());
        }

        $this->commandBus->dispatch(new CancelSpecialLeaveUsage(
            userId: $event->userId,
            requestId: $event->aggregateRootUuid(),
            reason: '特別休暇申請の差戻しによる消化記録の取消',
            viaReactor: true,
            initiatedByUserId: $event->returnedByUserId,
        ));
    }

    public function onSpecialLeaveRequestCancelled(SpecialLeaveRequestCancelled $event): void
    {
        if ($event->userId === null) {
            throw new \LogicException('取消のイベントに申請者がありません: '.$event->aggregateRootUuid());
        }

        $this->commandBus->dispatch(new CancelSpecialLeaveUsage(
            userId: $event->userId,
            requestId: $event->aggregateRootUuid(),
            reason: $event->reason ?? '特別休暇申請の取消',
            viaReactor: true,
            initiatedByUserId: $event->cancelledByUserId,
        ));
    }

    private function designate(
        string $requestId,
        string $userId,
        int $specialLeaveTypeId,
        string $targetDate,
        string $leaveType,
        ?float $hours,
        float $requestedDays,
        ?string $initiatedByUserId,
    ): void {
        $this->commandBus->dispatch(new DesignateSpecialLeaveUsage(
            userId: $userId,
            requestId: $requestId,
            specialLeaveTypeId: $specialLeaveTypeId,
            usedOn: $targetDate,
            usageType: $leaveType,
            usedDays: $requestedDays,
            usedMinutes: $hours !== null ? (int) round($hours * 60) : null,
            viaReactor: true,
            initiatedByUserId: $initiatedByUserId,
        ));
    }
}
