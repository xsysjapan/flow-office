<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Commands\DeleteAttendancePunch;
use App\Domain\Attendance\Events\AttendancePunchDeleted;
use App\Domain\Attendance\Services\AttendanceDayPunchSyncer;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\AttendancePunch;
use App\Models\PunchStatus;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * UC-A014: 打刻ログを削除する。行は物理削除せず「削除済み」として残す(打刻ログは
 * 追記のみで、削除自体も操作の履歴として理由・実行者付きで参照できるようにする)。
 * 削除後、対象日を打刻ログから組み立て直せるか再判定する。
 *
 * @implements CommandHandler<DeleteAttendancePunch>
 */
class DeleteAttendancePunchHandler implements CommandHandler
{
    public function __construct(
        private readonly EventStore $eventStore,
        private readonly AttendanceDayPunchSyncer $syncer,
    ) {}

    public function handle(Command $command): AttendancePunch
    {
        assert($command instanceof DeleteAttendancePunch);

        $punch = AttendancePunch::query()->findOrFail($command->attendancePunchId);

        if (! $punch->isActive()) {
            throw new DomainRuleException('既に訂正・削除済みの打刻ログです。');
        }

        $user = User::query()->findOrFail($punch->user_id);
        $workDate = $punch->work_date->toDateString();

        $punch->status = PunchStatus::DELETED;
        $punch->correction_reason = $command->reason;
        $punch->corrected_by_user_id = $command->deletedByUserId;
        $punch->corrected_at = Carbon::now();
        $punch->save();

        $this->eventStore->append(
            aggregateType: 'attendance_punch',
            aggregateId: (string) $punch->id,
            event: new AttendancePunchDeleted(
                attendancePunchId: $punch->id,
                reason: $command->reason,
                deletedByUserId: $command->deletedByUserId,
            ),
        );

        $this->syncer->sync($user, $workDate);

        return $punch;
    }
}
