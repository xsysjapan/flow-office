<?php

namespace App\Domain\CompensatoryLeaveAccount\Reactors;

use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequestApproved;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequestCancelled;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequested;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequestResubmitted;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequestReturned;
use App\Domain\CompensatoryLeaveAccount\Commands\CancelCompensatoryLeaveUsage;
use App\Domain\CompensatoryLeaveAccount\Commands\ConfirmCompensatoryLeaveUsage;
use App\Domain\CompensatoryLeaveAccount\Commands\DesignateCompensatoryLeaveUsage;
use App\Domain\EventSourcing\CommandBus;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * 代休申請の申請状態の変化(compensatory_leave.request_*)を受けて、代休口座(CompensatoryLeaveAccountAggregate)の
 * 消化記録を作成・確定・取消する(残数・使用の文脈のReactor。原則15)。SpecialLeaveUsageOnSpecialLeaveRequestReactorと同じ形。
 *
 * - requested / resubmitted → 消化記録の作成(再提出は新しい消化記録。論点8)
 * - approved → 消化記録の確定(残数不足でも確定し、充当できた分だけ充当。論点17)
 * - returned / cancelled → 消化記録の取消(承認済みは充当を解除し残数を戻す)
 *
 * 対象の消化記録は申請IDで特定する。全て viaReactor=true で発行し、既に目的の状態なら何もしない(冪等)。
 */
class CompensatoryLeaveUsageOnCompensatoryLeaveRequestReactor extends Reactor
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function onCompensatoryLeaveRequested(CompensatoryLeaveRequested $event): void
    {
        $this->designate(
            requestId: $event->aggregateRootUuid(),
            userId: $event->userId,
            usedOn: $event->targetDate,
            leaveType: $event->leaveType,
            requestedDays: $event->requestedDays,
            requestedMinutes: $event->requestedMinutes,
            initiatedByUserId: $event->userId,
        );
    }

    public function onCompensatoryLeaveRequestResubmitted(CompensatoryLeaveRequestResubmitted $event): void
    {
        if ($event->userId === null
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
            usedOn: $event->targetDate,
            leaveType: $event->leaveType,
            requestedDays: $event->requestedDays,
            requestedMinutes: $event->requestedMinutes,
            initiatedByUserId: $event->resubmittedByUserId ?? $event->userId,
        );
    }

    public function onCompensatoryLeaveRequestApproved(CompensatoryLeaveRequestApproved $event): void
    {
        if ($event->userId === null) {
            throw new \LogicException('承認のイベントに申請者がありません: '.$event->aggregateRootUuid());
        }

        $this->commandBus->dispatch(new ConfirmCompensatoryLeaveUsage(
            userId: $event->userId,
            requestId: $event->aggregateRootUuid(),
            viaReactor: true,
            initiatedByUserId: $event->approvedByUserId,
        ));
    }

    public function onCompensatoryLeaveRequestReturned(CompensatoryLeaveRequestReturned $event): void
    {
        if ($event->userId === null) {
            throw new \LogicException('差戻しのイベントに申請者がありません: '.$event->aggregateRootUuid());
        }

        $this->commandBus->dispatch(new CancelCompensatoryLeaveUsage(
            userId: $event->userId,
            requestId: $event->aggregateRootUuid(),
            reason: '代休申請の差戻しによる消化記録の取消',
            viaReactor: true,
            initiatedByUserId: $event->returnedByUserId,
        ));
    }

    public function onCompensatoryLeaveRequestCancelled(CompensatoryLeaveRequestCancelled $event): void
    {
        if ($event->userId === null) {
            throw new \LogicException('取消のイベントに申請者がありません: '.$event->aggregateRootUuid());
        }

        $this->commandBus->dispatch(new CancelCompensatoryLeaveUsage(
            userId: $event->userId,
            requestId: $event->aggregateRootUuid(),
            reason: $event->reason ?? '代休申請の取消',
            viaReactor: true,
            initiatedByUserId: $event->cancelledByUserId,
        ));
    }

    private function designate(
        string $requestId,
        string $userId,
        string $usedOn,
        string $leaveType,
        ?float $requestedDays,
        ?int $requestedMinutes,
        ?string $initiatedByUserId,
    ): void {
        $this->commandBus->dispatch(new DesignateCompensatoryLeaveUsage(
            userId: $userId,
            requestId: $requestId,
            usedOn: $usedOn,
            usageType: $leaveType,
            usedDays: (float) ($requestedDays ?? 0.0),
            usedMinutes: $requestedMinutes,
            viaReactor: true,
            initiatedByUserId: $initiatedByUserId,
        ));
    }
}
