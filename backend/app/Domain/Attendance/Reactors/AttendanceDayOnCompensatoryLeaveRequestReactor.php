<?php

namespace App\Domain\Attendance\Reactors;

use App\Domain\Attendance\Commands\ApplyLeaveToAttendanceDay;
use App\Domain\Attendance\Commands\RecalculateAttendanceDayForLeave;
use App\Domain\Attendance\Commands\ReleaseLeaveFromAttendanceDay;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequestApproved;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequestCancelled;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequested;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequestResubmitted;
use App\Domain\CompensatoryLeave\Events\CompensatoryLeaveRequestReturned;
use App\Domain\EventSourcing\CommandBus;
use App\Models\AttendanceDayLeave;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * 代休申請の変化(compensatory_leave.request_*。代休申請文脈のイベント)を受けて、勤怠日へ休暇を反映する
 * (勤怠文脈のReactor。原則15)。AttendanceDayOnSpecialLeaveRequestReactorと同じ形で、種類だけ代休にする。
 *
 * - requested / resubmitted → ApplyLeaveToAttendanceDay(締め判定・同日衝突判定・勤怠日の作成・日次計算)
 * - approved → RecalculateAttendanceDayForLeave(締め判定・日次計算)
 * - returned / cancelled → ReleaseLeaveFromAttendanceDay(締め判定・勤怠日の削除または日次計算)
 *
 * 代休は勤怠計算の休暇日数に算入しない(対象外。日次計算の値は休暇ビューの代休の行で変わらない)。
 * 全て viaReactor=true で発行し、initiatedByUserId は連鎖の起点の操作者を引き継ぐ。
 */
class AttendanceDayOnCompensatoryLeaveRequestReactor extends Reactor
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function onCompensatoryLeaveRequested(CompensatoryLeaveRequested $event): void
    {
        $this->commandBus->dispatch(new ApplyLeaveToAttendanceDay(
            userId: $event->userId,
            workDate: $event->targetDate,
            leaveKind: AttendanceDayLeave::KIND_COMPENSATORY,
            leaveRequestId: $event->aggregateRootUuid(),
            viaReactor: true,
            initiatedByUserId: $event->userId,
        ));
    }

    public function onCompensatoryLeaveRequestResubmitted(CompensatoryLeaveRequestResubmitted $event): void
    {
        if ($event->userId === null || $event->targetDate === null) {
            throw new \LogicException('再提出のイベントに申請者・対象日がありません: '.$event->aggregateRootUuid());
        }

        $this->commandBus->dispatch(new ApplyLeaveToAttendanceDay(
            userId: $event->userId,
            workDate: $event->targetDate,
            leaveKind: AttendanceDayLeave::KIND_COMPENSATORY,
            leaveRequestId: $event->aggregateRootUuid(),
            viaReactor: true,
            initiatedByUserId: $event->resubmittedByUserId ?? $event->userId,
        ));
    }

    public function onCompensatoryLeaveRequestApproved(CompensatoryLeaveRequestApproved $event): void
    {
        $this->commandBus->dispatch(new RecalculateAttendanceDayForLeave(
            leaveKind: AttendanceDayLeave::KIND_COMPENSATORY,
            leaveRequestId: $event->aggregateRootUuid(),
            viaReactor: true,
            initiatedByUserId: $event->approvedByUserId,
        ));
    }

    public function onCompensatoryLeaveRequestReturned(CompensatoryLeaveRequestReturned $event): void
    {
        $this->commandBus->dispatch(new ReleaseLeaveFromAttendanceDay(
            leaveKind: AttendanceDayLeave::KIND_COMPENSATORY,
            leaveRequestId: $event->aggregateRootUuid(),
            viaReactor: true,
            initiatedByUserId: $event->returnedByUserId,
        ));
    }

    public function onCompensatoryLeaveRequestCancelled(CompensatoryLeaveRequestCancelled $event): void
    {
        $this->commandBus->dispatch(new ReleaseLeaveFromAttendanceDay(
            leaveKind: AttendanceDayLeave::KIND_COMPENSATORY,
            leaveRequestId: $event->aggregateRootUuid(),
            viaReactor: true,
            initiatedByUserId: $event->cancelledByUserId,
        ));
    }
}
