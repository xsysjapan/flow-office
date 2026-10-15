<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Commands\RecalculateAttendanceDayForLeave;
use App\Domain\Attendance\Services\AttendanceEditGuard;
use App\Domain\Attendance\Services\LeaveAttendanceDayRecorder;
use App\Domain\Attendance\Support\AttendanceDayLeaves;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;

/**
 * 休暇の承認を勤怠へ反映する。手順: (a) 締め判定(管理者の例外なし)、(b) 勤怠日が無ければ作成、
 * (c) 日次計算の記録。対象日は休暇ビューの行から読む。
 *
 * @implements CommandHandler<RecalculateAttendanceDayForLeave>
 */
class RecalculateAttendanceDayForLeaveHandler implements CommandHandler
{
    public function __construct(
        private readonly AttendanceEditGuard $guard,
        private readonly AttendanceDayLeaves $leaves,
        private readonly LeaveAttendanceDayRecorder $recorder,
    ) {}

    public function handle(Command $command): mixed
    {
        assert($command instanceof RecalculateAttendanceDayForLeave);

        $leave = $this->leaves->findByRequest($command->leaveKind, $command->leaveRequestId);
        if ($leave === null) {
            throw new \LogicException("休暇ビューに休暇がありません: {$command->leaveKind}/{$command->leaveRequestId}");
        }

        $userId = (string) $leave['user_id'];
        $workDate = (string) $leave['work_date'];

        $this->guard->assertMutable($this->recorder->dayOf($userId, $workDate), $userId, $workDate);

        $day = $this->recorder->ensureDay($userId, $workDate, $command->initiatedByUserId ?? $userId);
        $this->recorder->recalculate($day);

        return null;
    }
}
