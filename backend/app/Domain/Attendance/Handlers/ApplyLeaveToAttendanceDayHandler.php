<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Commands\ApplyLeaveToAttendanceDay;
use App\Domain\Attendance\Services\AttendanceEditGuard;
use App\Domain\Attendance\Services\LeaveAttendanceDayRecorder;
use App\Domain\Attendance\Support\AttendanceDayLeaves;
use App\Domain\Attendance\Support\LeaveDayConflictPolicy;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;

/**
 * 休暇の申請・再提出を勤怠へ反映する。手順: (a) 締め判定(管理者の例外なし)、(b) 同じ日の有効な休暇との
 * 衝突判定(今回の休暇自身は除く)、(c) 勤怠日が無ければ作成、(d) 日次計算の記録。
 * 違反は DomainRuleException で、連鎖全体(申請の作成を含む)が取り消される。
 *
 * @implements CommandHandler<ApplyLeaveToAttendanceDay>
 */
class ApplyLeaveToAttendanceDayHandler implements CommandHandler
{
    public function __construct(
        private readonly AttendanceEditGuard $guard,
        private readonly AttendanceDayLeaves $leaves,
        private readonly LeaveDayConflictPolicy $conflictPolicy,
        private readonly LeaveAttendanceDayRecorder $recorder,
    ) {}

    public function handle(Command $command): mixed
    {
        assert($command instanceof ApplyLeaveToAttendanceDay);

        $leave = $this->leaves->findByRequest($command->leaveKind, $command->leaveRequestId);
        if ($leave === null) {
            // 休暇ビューは休暇申請のイベントと同じ連鎖でProjectorが作るため、ここで無いのは不整合のみ。
            throw new \LogicException("休暇ビューに休暇がありません: {$command->leaveKind}/{$command->leaveRequestId}");
        }

        $userId = (string) $leave['user_id'];
        $workDate = (string) $leave['work_date'];

        $this->guard->assertMutable($this->recorder->dayOf($userId, $workDate), $userId, $workDate);

        $others = array_values(array_filter(
            $this->leaves->activeFor($userId, $workDate),
            fn (array $other): bool => ! ($other['leave_kind'] === $leave['leave_kind']
                && $other['leave_request_id'] === $leave['leave_request_id']),
        ));

        $conflict = $this->conflictPolicy->check(
            $others,
            ['unit' => $leave['unit'], 'minutes' => $leave['minutes']],
            $this->recorder->prescribedMinutes($userId, $workDate),
        );
        if ($conflict !== null) {
            throw new DomainRuleException($conflict);
        }

        $day = $this->recorder->ensureDay($userId, $workDate, $command->initiatedByUserId ?? $userId);
        $this->recorder->recalculate($day);

        return null;
    }
}
