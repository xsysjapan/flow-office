<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Commands\DeleteAttendanceDay;
use App\Domain\Attendance\Events\AttendanceDayDeleted;
use App\Domain\Attendance\Services\AttendanceEditGuard;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\AttendanceDay;

/**
 * UC-A015: 日次勤怠を削除する。承認前(未提出・提出済み・差戻し)のみ可能で、
 * 承認済み・締め済みの日次勤怠は削除できない(AttendanceEditGuard参照)。有給消化済みの
 * 日は、有給残数の整合性が崩れるため先に有給申請の取消が必要。
 *
 * @implements CommandHandler<DeleteAttendanceDay>
 */
class DeleteAttendanceDayHandler implements CommandHandler
{
    public function __construct(
        private readonly EventStore $eventStore,
        private readonly AttendanceEditGuard $guard,
    ) {}

    public function handle(Command $command): mixed
    {
        assert($command instanceof DeleteAttendanceDay);

        $day = AttendanceDay::query()->findOrFail($command->attendanceDayId);
        $workDate = $day->work_date->toDateString();

        $this->guard->assertMutable($day, $day->user_id, $workDate);

        if ($day->paidLeaveUsages()->exists()) {
            throw new DomainRuleException('有給消化済みの日次勤怠は削除できません。先に有給申請の取消を行ってください。');
        }

        $this->eventStore->append(
            aggregateType: 'attendance_day',
            aggregateId: (string) $day->id,
            event: new AttendanceDayDeleted(
                attendanceDayId: $day->id,
                userId: $day->user_id,
                workDate: $workDate,
                reason: $command->reason,
                deletedByUserId: $command->deletedByUserId,
            ),
        );

        // attendance_breaks / attendance_daily_calculations は外部キーのcascadeOnDeleteで
        // 併せて削除される。paid_leave_usages は上のチェックで存在しないことを保証済み。
        $day->delete();

        return null;
    }
}
