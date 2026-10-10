<?php

namespace App\Domain\Attendance\Reactors;

use App\Domain\Attendance\Commands\ApplyLeaveToAttendanceDay;
use App\Domain\Attendance\Commands\RecalculateAttendanceDayForLeave;
use App\Domain\Attendance\Commands\ReleaseLeaveFromAttendanceDay;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequestApproved;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequestCancelled;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequested;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequestResubmitted;
use App\Domain\SpecialLeave\Events\SpecialLeaveRequestReturned;
use App\Models\AttendanceDayLeave;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * 特別休暇申請の変化(special_leave_request.*相当。特別休暇申請文脈のイベント)を受けて、勤怠日へ休暇を反映する
 * (勤怠文脈のReactor。原則15)。AttendanceDayOnPaidLeaveRequestReactorと同じ形で、種類だけ特別休暇にする。
 *
 * - requested / resubmitted → ApplyLeaveToAttendanceDay(締め判定・同日衝突判定・勤怠日の作成・日次計算)
 * - approved → RecalculateAttendanceDayForLeave(締め判定・日次計算)
 * - returned / cancelled → ReleaseLeaveFromAttendanceDay(締め判定・勤怠日の削除または日次計算)
 *
 * 対象日・取得単位は休暇ビュー(attendance_day_leaves)の行から勤怠側が読む。全て viaReactor=true で発行し、
 * initiatedByUserId は連鎖の起点の操作者を引き継ぐ。例外は連鎖全体(申請・承認の状態変更を含む)を取り消す。
 */
class AttendanceDayOnSpecialLeaveRequestReactor extends Reactor
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function onSpecialLeaveRequested(SpecialLeaveRequested $event): void
    {
        $this->commandBus->dispatch(new ApplyLeaveToAttendanceDay(
            userId: $event->userId,
            workDate: $event->targetDate,
            leaveKind: AttendanceDayLeave::KIND_SPECIAL,
            leaveRequestId: $event->aggregateRootUuid(),
            viaReactor: true,
            initiatedByUserId: $event->userId,
        ));
    }

    public function onSpecialLeaveRequestResubmitted(SpecialLeaveRequestResubmitted $event): void
    {
        if ($event->userId === null || $event->targetDate === null) {
            throw new \LogicException('再提出のイベントに申請者・対象日がありません: '.$event->aggregateRootUuid());
        }

        $this->commandBus->dispatch(new ApplyLeaveToAttendanceDay(
            userId: $event->userId,
            workDate: $event->targetDate,
            leaveKind: AttendanceDayLeave::KIND_SPECIAL,
            leaveRequestId: $event->aggregateRootUuid(),
            viaReactor: true,
            initiatedByUserId: $event->resubmittedByUserId ?? $event->userId,
        ));
    }

    public function onSpecialLeaveRequestApproved(SpecialLeaveRequestApproved $event): void
    {
        $this->commandBus->dispatch(new RecalculateAttendanceDayForLeave(
            leaveKind: AttendanceDayLeave::KIND_SPECIAL,
            leaveRequestId: $event->aggregateRootUuid(),
            viaReactor: true,
            initiatedByUserId: $event->approvedByUserId,
        ));
    }

    public function onSpecialLeaveRequestReturned(SpecialLeaveRequestReturned $event): void
    {
        $this->commandBus->dispatch(new ReleaseLeaveFromAttendanceDay(
            leaveKind: AttendanceDayLeave::KIND_SPECIAL,
            leaveRequestId: $event->aggregateRootUuid(),
            viaReactor: true,
            initiatedByUserId: $event->returnedByUserId,
        ));
    }

    public function onSpecialLeaveRequestCancelled(SpecialLeaveRequestCancelled $event): void
    {
        $this->commandBus->dispatch(new ReleaseLeaveFromAttendanceDay(
            leaveKind: AttendanceDayLeave::KIND_SPECIAL,
            leaveRequestId: $event->aggregateRootUuid(),
            viaReactor: true,
            initiatedByUserId: $event->cancelledByUserId,
        ));
    }
}
