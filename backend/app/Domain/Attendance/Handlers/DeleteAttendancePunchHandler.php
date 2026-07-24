<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\AttendancePunchAggregate;
use App\Domain\Attendance\Commands\DeleteAttendancePunch;
use App\Domain\Attendance\Services\AttendanceDayPunchSyncer;
use App\Domain\Attendance\Services\AttendanceEditGuard;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\AttendanceDay;
use App\Models\AttendancePunch;
use App\Models\PunchStatus;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * UC-A014: 打刻ログを削除する。行は物理削除せず「削除済み」として残す(打刻ログは
 * 追記のみで、削除自体も操作の履歴として理由・実行者付きで参照できるようにする)。
 * 対象日が締め後・承認済み月に属する場合は、打刻ログ自体の削除もできない
 * (AttendanceEditGuard参照。理由はUC-A013と同じ)。削除後、対象日を打刻ログから
 * 組み立て直せるか再判定する。打刻の削除と、それによって生じる日次勤怠への反映は、
 * `AggregateRoot::persistInTransaction()`で1トランザクションにまとめて記録する。
 *
 * @implements CommandHandler<DeleteAttendancePunch>
 */
class DeleteAttendancePunchHandler implements CommandHandler
{
    public function __construct(
        private readonly AttendanceDayPunchSyncer $syncer,
        private readonly AttendanceEditGuard $guard,
    ) {}

    public function handle(Command $command): AttendancePunch
    {
        assert($command instanceof DeleteAttendancePunch);

        $punch = AttendancePunch::query()->findOrFail($command->attendancePunchId);

        if (! $punch->isActive()) {
            throw new DomainRuleException('既に訂正・削除済みの打刻ログです。');
        }

        $workDate = $punch->work_date->toDateString();
        $day = AttendanceDay::query()
            ->where('user_id', $punch->user_id)
            ->whereDate('work_date', $workDate)
            ->first();
        $this->guard->assertMutable($day, $punch->user_id, $workDate);

        $punchAggregate = AttendancePunchAggregate::retrieve($punch->id)->delete(
            reason: $command->reason,
            deletedByUserId: $command->deletedByUserId,
        );

        $activePunches = AttendancePunch::query()
            ->where('user_id', $punch->user_id)
            ->whereDate('work_date', $workDate)
            ->where('status', PunchStatus::ACTIVE)
            ->where('id', '!=', $punch->id)
            ->with('device')
            ->get();

        $dayAggregate = $this->syncer->prepare($punch->user_id, $workDate, $activePunches);

        AggregateRoot::persistInTransaction(...array_filter([$punchAggregate, $dayAggregate]));

        return AttendancePunch::query()->findOrFail($punch->id);
    }
}
