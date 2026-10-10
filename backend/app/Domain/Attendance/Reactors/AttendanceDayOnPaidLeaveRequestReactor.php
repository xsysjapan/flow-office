<?php

namespace App\Domain\Attendance\Reactors;

use App\Domain\Attendance\Commands\ApplyLeaveToAttendanceDay;
use App\Domain\Attendance\Commands\RecalculateAttendanceDayForLeave;
use App\Domain\Attendance\Commands\ReleaseLeaveFromAttendanceDay;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\PaidLeaveRequest\Events\PaidLeaveRequestLifecycleApproved;
use App\Domain\PaidLeaveRequest\Events\PaidLeaveRequestLifecycleCancelled;
use App\Domain\PaidLeaveRequest\Events\PaidLeaveRequestLifecycleRequested;
use App\Domain\PaidLeaveRequest\Events\PaidLeaveRequestLifecycleResubmitted;
use App\Domain\PaidLeaveRequest\Events\PaidLeaveRequestLifecycleReturned;
use App\Models\AttendanceDayLeave;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * 休暇申請文脈の変化(有給申請 paid_leave_request.*)を受けて、勤怠日へ休暇を反映する(勤怠文脈のReactor。原則15)。
 *
 * - requested / resubmitted → ApplyLeaveToAttendanceDay(締め判定・同日衝突判定・勤怠日の作成・日次計算)
 * - approved → RecalculateAttendanceDayForLeave(締め判定・日次計算)
 * - returned / cancelled → ReleaseLeaveFromAttendanceDay(締め判定・勤怠日の削除または日次計算)
 *
 * 休暇ビュー(attendance_day_leaves)のProjectorは同じイベントのReactorより先に処理される前提で、
 * 対象日・取得単位は休暇ビューの行から読む。特別休暇・代休はこのReactorの対象外(配線替えのときに追加する)。
 * 全て viaReactor=true で発行し、initiatedByUserId は連鎖の起点の操作者を引き継ぐ。
 * 例外は連鎖全体(申請・承認の状態変更を含む)を取り消す。
 */
class AttendanceDayOnPaidLeaveRequestReactor extends Reactor
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function onPaidLeaveRequestLifecycleRequested(PaidLeaveRequestLifecycleRequested $event): void
    {
        if ($event->userId === null || $event->targetDate === null) {
            throw new \LogicException('申請のイベントに申請者・対象日がありません: '.$event->aggregateRootUuid());
        }

        $this->commandBus->dispatch(new ApplyLeaveToAttendanceDay(
            userId: $event->userId,
            workDate: $event->targetDate,
            leaveKind: AttendanceDayLeave::KIND_PAID,
            leaveRequestId: $event->aggregateRootUuid(),
            viaReactor: true,
            initiatedByUserId: $event->userId,
        ));
    }

    public function onPaidLeaveRequestLifecycleResubmitted(PaidLeaveRequestLifecycleResubmitted $event): void
    {
        if ($event->userId === null || $event->targetDate === null) {
            throw new \LogicException('再提出のイベントに申請者・対象日がありません: '.$event->aggregateRootUuid());
        }

        $this->commandBus->dispatch(new ApplyLeaveToAttendanceDay(
            userId: $event->userId,
            workDate: $event->targetDate,
            leaveKind: AttendanceDayLeave::KIND_PAID,
            leaveRequestId: $event->aggregateRootUuid(),
            viaReactor: true,
            initiatedByUserId: $event->resubmittedByUserId ?? $event->userId,
        ));
    }

    public function onPaidLeaveRequestLifecycleApproved(PaidLeaveRequestLifecycleApproved $event): void
    {
        $this->commandBus->dispatch(new RecalculateAttendanceDayForLeave(
            leaveKind: AttendanceDayLeave::KIND_PAID,
            leaveRequestId: $event->aggregateRootUuid(),
            viaReactor: true,
            initiatedByUserId: $event->approvedByUserId,
        ));
    }

    public function onPaidLeaveRequestLifecycleReturned(PaidLeaveRequestLifecycleReturned $event): void
    {
        $this->commandBus->dispatch(new ReleaseLeaveFromAttendanceDay(
            leaveKind: AttendanceDayLeave::KIND_PAID,
            leaveRequestId: $event->aggregateRootUuid(),
            viaReactor: true,
            initiatedByUserId: $event->returnedByUserId,
        ));
    }

    public function onPaidLeaveRequestLifecycleCancelled(PaidLeaveRequestLifecycleCancelled $event): void
    {
        $this->commandBus->dispatch(new ReleaseLeaveFromAttendanceDay(
            leaveKind: AttendanceDayLeave::KIND_PAID,
            leaveRequestId: $event->aggregateRootUuid(),
            viaReactor: true,
            initiatedByUserId: $event->cancelledByUserId,
        ));
    }
}
