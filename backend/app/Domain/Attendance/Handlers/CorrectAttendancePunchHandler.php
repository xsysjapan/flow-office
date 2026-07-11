<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Commands\CorrectAttendancePunch;
use App\Domain\Attendance\Events\AttendancePunchCorrected;
use App\Domain\Attendance\Services\AttendanceDayPunchSyncer;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\AttendancePunch;
use App\Models\PunchStatus;
use App\Models\User;
use App\Support\LocalDateTime;
use Illuminate\Support\Carbon;

/**
 * UC-A013: 打刻ログを訂正する。打刻ログは追記のみのため、元の行は書き換えず「訂正済み」
 * として残し、訂正後の値を新しい打刻行として追記する。矛盾なく組み立てられる場合のみ
 * 対象日の勤怠(attendance_days)に反映し直す(打刻以外の経路で確定済み・締め後・
 * 承認済み月に属する日は上書きしない)。
 *
 * @implements CommandHandler<CorrectAttendancePunch>
 */
class CorrectAttendancePunchHandler implements CommandHandler
{
    public function __construct(
        private readonly EventStore $eventStore,
        private readonly AttendanceDayPunchSyncer $syncer,
    ) {}

    public function handle(Command $command): AttendancePunch
    {
        assert($command instanceof CorrectAttendancePunch);

        $original = AttendancePunch::query()->findOrFail($command->attendancePunchId);

        if (! $original->isActive()) {
            throw new DomainRuleException('既に訂正・削除済みの打刻ログは重ねて訂正できません。');
        }

        $user = User::query()->findOrFail($original->user_id);
        $workDate = $original->work_date->toDateString();

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

        $this->syncer->sync($user, $workDate);

        return $corrected;
    }
}
