<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\AttendanceDayAggregate;
use App\Domain\Attendance\Aggregates\AttendancePunchAggregate;
use App\Domain\Attendance\Commands\DeleteAttendanceDay;
use App\Domain\Attendance\Services\AttendanceDayPunchSyncer;
use App\Domain\Attendance\Services\AttendanceEditGuard;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\AttendanceDay;
use App\Models\AttendancePunch;
use App\Models\PunchStatus;

/**
 * UC-A015: 日次勤怠を削除する。承認前(未提出・提出済み・差戻し)のみ可能で、
 * 承認済み・締め済みの日次勤怠は削除できない(AttendanceEditGuard参照)。有給・特別休暇の
 * 消化済みの日は、残数の整合性が崩れるため削除できない(承認済みの申請を取り消す機能は
 * 現状無いため、この場合は削除不可が最終的な扱いになる)。
 *
 * @implements CommandHandler<DeleteAttendanceDay>
 */
class DeleteAttendanceDayHandler implements CommandHandler
{
    public function __construct(
        private readonly AttendanceEditGuard $guard,
        private readonly AttendanceDayPunchSyncer $punchSyncer,
    ) {}

    public function handle(Command $command): mixed
    {
        assert($command instanceof DeleteAttendanceDay);

        $day = AttendanceDay::query()->findOrFail($command->attendanceDayId);
        $workDate = $day->work_date->toDateString();

        $this->guard->assertMutable($day, $day->user_id, $workDate);

        if ($day->paidLeaveUsages()->exists()) {
            throw new DomainRuleException('有給消化済みの日次勤怠は削除できません。');
        }

        if ($day->specialLeaveUsages()->exists()) {
            throw new DomainRuleException('特別休暇消化済みの日次勤怠は削除できません。');
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
