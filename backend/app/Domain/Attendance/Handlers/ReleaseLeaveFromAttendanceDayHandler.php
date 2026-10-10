<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\AttendanceDayAggregate;
use App\Domain\Attendance\Commands\DeleteAttendanceDay;
use App\Domain\Attendance\Commands\ReleaseLeaveFromAttendanceDay;
use App\Domain\Attendance\Services\AttendanceEditGuard;
use App\Domain\Attendance\Services\LeaveAttendanceDayRecorder;
use App\Domain\Attendance\Services\LeaveReleaseDayPolicy;
use App\Domain\Attendance\Support\AttendanceDayLeaves;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;

/**
 * 休暇の差戻し・取消を勤怠へ反映する。手順: (a) 締め判定(管理者の例外なし)、(b) 論点15の条件を満たせば
 * 勤怠日を削除(休暇の前の状態に戻す)、満たさなければ日次計算を記録する。
 *
 * viaReactor=true で勤怠日が既に無ければ何もしない(冪等)。利用者の操作として来た場合は状態不正を例外にする。
 *
 * @implements CommandHandler<ReleaseLeaveFromAttendanceDay>
 */
class ReleaseLeaveFromAttendanceDayHandler implements CommandHandler
{
    public function __construct(
        private readonly AttendanceEditGuard $guard,
        private readonly AttendanceDayLeaves $leaves,
        private readonly LeaveAttendanceDayRecorder $recorder,
        private readonly LeaveReleaseDayPolicy $releasePolicy,
    ) {}

    public function handle(Command $command): mixed
    {
        assert($command instanceof ReleaseLeaveFromAttendanceDay);

        $leave = $this->leaves->findByRequest($command->leaveKind, $command->leaveRequestId);
        if ($leave === null) {
            // 休暇ビューに行が無い(対象日が分からない)。申請時に勤怠日を作っていないため何もしない。
            return null;
        }

        $userId = (string) $leave['user_id'];
        $workDate = (string) $leave['work_date'];
        $day = $this->recorder->dayOf($userId, $workDate);

        $this->guard->assertMutable($day, $userId, $workDate);

        if ($day === null) {
            if ($command->viaReactor) {
                return null;
            }

            throw new DomainRuleException('勤怠日が存在しないため休暇を解除できません。');
        }

        $hasOtherActiveLeave = $this->leaves->activeFor($userId, $workDate) !== [];

        if ($this->releasePolicy->isRemovableAfterLeaveRelease($day, $hasOtherActiveLeave)) {
            $actorUserId = $command->initiatedByUserId ?? $userId;

            AttendanceDayAggregate::retrieve($day->id)
                ->delete(
                    userId: $userId,
                    workDate: $workDate,
                    reason: '休暇の差戻し・取消に伴い、休暇のために作成した勤怠日を削除',
                    deletedByUserId: $actorUserId,
                    punchLogAction: DeleteAttendanceDay::LEAVE_PUNCHES,
                )
                ->persist();

            return null;
        }

        $this->recorder->recalculate($day);

        return null;
    }
}
