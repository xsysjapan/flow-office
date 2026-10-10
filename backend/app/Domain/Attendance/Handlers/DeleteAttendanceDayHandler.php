<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\AttendanceDayAggregate;
use App\Domain\Attendance\Aggregates\AttendancePunchAggregate;
use App\Domain\Attendance\Commands\DeleteAttendanceDay;
use App\Domain\Attendance\Services\AttendanceDayPunchSyncer;
use App\Domain\Attendance\Services\AttendanceEditGuard;
use App\Domain\Attendance\Support\AttendanceDayLeaves;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\AttendanceDay;
use App\Models\AttendancePunch;
use App\Models\PunchStatus;

/**
 * UC-A015: 日次勤怠を削除する。承認前(未提出・提出済み・差戻し)のみ可能で、
 * 承認済み・締め済みの日次勤怠は削除できない(AttendanceEditGuard参照)。有効な休暇
 * (申請中・承認済み)がある日は、休暇の整合性が崩れるため削除できない(休暇を取り消してから削除する)。
 *
 * @implements CommandHandler<DeleteAttendanceDay>
 */
class DeleteAttendanceDayHandler implements CommandHandler
{
    public function __construct(
        private readonly AttendanceEditGuard $guard,
        private readonly AttendanceDayPunchSyncer $punchSyncer,
        private readonly AttendanceDayLeaves $leaves,
    ) {}

    public function handle(Command $command): mixed
    {
        assert($command instanceof DeleteAttendanceDay);

        $day = AttendanceDay::query()->findOrFail($command->attendanceDayId);
        $workDate = $day->work_date->toDateString();

        $this->guard->assertMutable($day, $day->user_id, $workDate);

        // 有効な休暇(申請中・承認済み)がある日は削除できない。判定は休暇ビュー(attendance_day_leaves)で行い、
        // 消化記録(paid_leave_usages・special_leave_usages)は見ない。
        if ($this->leaves->activeFor($day->user_id, $workDate) !== []) {
            throw new DomainRuleException('休暇(有給・特別休暇・代休)が申請・承認されている日次勤怠は削除できません。休暇を取り消してから削除してください。');
        }

        AttendanceDayAggregate::retrieve($day->id)
            ->delete($day->user_id, $workDate, $command->reason, $command->deletedByUserId, $command->punchLogAction)
            ->persist();

        // attendance_breaks / attendance_leave_segments / attendance_daily_calculations は
        // 外部キーのcascadeOnDeleteで併せて削除される。paid_leave_usages は上のチェックで
        // 存在しないことを保証済み。

        if ($command->punchLogAction === DeleteAttendanceDay::DELETE_PUNCHES) {
            AttendancePunch::query()
                ->where('user_id', $day->user_id)
                ->whereDate('work_date', $workDate)
                ->where('status', PunchStatus::ACTIVE)
                ->each(function (AttendancePunch $punch) use ($command): void {
                    AttendancePunchAggregate::retrieve($punch->id)
                        ->delete($command->reason, $command->deletedByUserId)
                        ->persist();
                });
        }

        if ($command->punchLogAction === DeleteAttendanceDay::RECREATE_FROM_PUNCHES) {
            $dayAggregate = $this->punchSyncer->prepare($day->user_id, $workDate);
            $dayAggregate?->persist();
        }

        return null;
    }
}
