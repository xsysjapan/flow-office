<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Commands\CorrectAttendancePunch;
use App\Domain\Attendance\Events\AttendancePunchCorrected;
use App\Domain\Attendance\Services\AttendanceDayPunchSyncer;
use App\Domain\Attendance\Services\AttendanceEditGuard;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\AttendanceDay;
use App\Models\AttendancePunch;
use App\Models\PunchStatus;
use App\Support\LocalDateTime;
use Illuminate\Support\Carbon;

/**
 * UC-A013: 打刻ログを訂正する。打刻ログは追記のみのため、元の行は書き換えず「訂正済み」
 * として残し、訂正後の値を新しい打刻行として追記する。対象日が締め後・承認済み月に
 * 属する場合は、打刻ログ自体の訂正もできない(AttendanceEditGuard参照。打刻ログの状態が
 * 変わることで、承認済みの記録に対する監査証跡が書き換わってしまうため)。
 *
 * @implements CommandHandler<CorrectAttendancePunch>
 */
class CorrectAttendancePunchHandler implements CommandHandler
{
    public function __construct(
        private readonly EventStore $eventStore,
        private readonly AttendanceDayPunchSyncer $syncer,
        private readonly AttendanceEditGuard $guard,
    ) {}

    public function handle(Command $command): AttendancePunch
    {
        assert($command instanceof CorrectAttendancePunch);

        $original = AttendancePunch::query()->findOrFail($command->attendancePunchId);

        if (! $original->isActive()) {
            throw new DomainRuleException('既に訂正・削除済みの打刻ログは重ねて訂正できません。');
        }

        $workDate = $original->work_date->toDateString();
        $day = AttendanceDay::query()
            ->where('user_id', $original->user_id)
            ->whereDate('work_date', $workDate)
            ->first();
        $this->guard->assertMutable($day, $original->user_id, $workDate);

        [$punchedAt, $utcOffsetMinutes] = LocalDateTime::splitOffset($command->punchedAt);

        $corrected = AttendancePunch::query()->create([
            'user_id' => $original->user_id,
            'work_date' => $workDate,
            'punch_type' => $command->punchType,
            'punched_at' => $punchedAt,
            'utc_offset_minutes' => $utcOffsetMinutes,
            'source' => $original->source,
            'note' => $original->note,
            'status' => PunchStatus::ACTIVE,
        ]);

        $original->status = PunchStatus::CORRECTED;
        $original->superseded_by_punch_id = $corrected->id;
        $original->correction_reason = $command->reason;
        $original->corrected_by_user_id = $command->correctedByUserId;
        $original->corrected_at = Carbon::now();
        $original->save();

        $this->eventStore->append(
            aggregateType: 'attendance_punch',
            aggregateId: (string) $original->id,
            event: new AttendancePunchCorrected(
                attendancePunchId: $original->id,
                correctedPunchId: $corrected->id,
                punchType: $command->punchType,
                punchedAt: LocalDateTime::formatWithOffsetMinutes($corrected->punched_at, $utcOffsetMinutes),
                reason: $command->reason,
                correctedByUserId: $command->correctedByUserId,
            ),
        );

        $this->syncer->sync($original->user_id, $workDate);

        return $corrected;
    }
}
