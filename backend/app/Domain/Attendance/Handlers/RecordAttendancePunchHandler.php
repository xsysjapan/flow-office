<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Commands\RecordAttendancePunch;
use App\Domain\Attendance\Events\AttendancePunchRecorded;
use App\Domain\Attendance\Services\AttendanceDayPunchSyncer;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Models\AttendancePunch;
use App\Models\User;
use App\Support\LocalDateTime;

/**
 * UC-A012: 打刻ログを記録する。矛盾があっても記録は必ず成功させ、
 * 矛盾なく1日分の勤務として組み立てられる場合のみ attendance_days に反映する。
 * punched_atはオフセット付きISO8601を前提に、送信された通りの壁時計時刻とUTCオフセット(分)を
 * そのまま保存する(user.timezoneへの変換はしない)。海外出張などで打刻元の現地時刻が
 * 社員本人の既定タイムゾーンと異なる場合でも、その打刻が実際に発生した現地時刻を維持する
 * (docs/03-architecture.md 3.4)。
 *
 * @implements CommandHandler<RecordAttendancePunch>
 */
class RecordAttendancePunchHandler implements CommandHandler
{
    public function __construct(
        private readonly EventStore $eventStore,
        private readonly AttendanceDayPunchSyncer $syncer,
    ) {}

    public function handle(Command $command): AttendancePunch
    {
        assert($command instanceof RecordAttendancePunch);

        $user = User::query()->findOrFail($command->userId);
        [$punchedAt, $utcOffsetMinutes] = LocalDateTime::splitOffset($command->punchedAt);

        $punch = AttendancePunch::query()->create([
            'user_id' => $command->userId,
            'work_date' => $command->workDate,
            'punch_type' => $command->punchType,
            'punched_at' => $punchedAt,
            'utc_offset_minutes' => $utcOffsetMinutes,
            'source' => $command->source,
            'note' => $command->note,
        ]);

        $this->eventStore->append(
            aggregateType: 'attendance_punch',
            aggregateId: (string) $punch->id,
            event: new AttendancePunchRecorded(
                attendancePunchId: $punch->id,
                userId: $command->userId,
                workDate: $command->workDate,
                punchType: $command->punchType,
                punchedAt: LocalDateTime::formatWithOffsetMinutes($punch->punched_at, $punch->utc_offset_minutes),
                source: $command->source,
            ),
        );

        $this->syncer->sync($user->id, $command->workDate);

        return $punch;
    }
}
