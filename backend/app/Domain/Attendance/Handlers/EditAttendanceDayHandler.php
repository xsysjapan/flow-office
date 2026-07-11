<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Commands\EditAttendanceDay;
use App\Domain\Attendance\Events\AttendanceDayCalculated;
use App\Domain\Attendance\Events\AttendanceDayEdited;
use App\Domain\Attendance\Services\AttendanceCalculator;
use App\Domain\Attendance\Services\AttendanceEditGuard;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\AttendanceDay;
use App\Models\AttendanceDaySource;
use App\Models\AttendanceDayStatus;
use App\Support\LocalDateTime;

/**
 * UC-A005: 日次勤怠を編集する。締め後(ロック後)、および承認済み・締め済みの月次に
 * 属する日次勤怠は修正申請ワークフローを使う(AttendanceEditGuard参照。UC-A015)。
 *
 * 入力される日時はオフセット付きISO8601を前提に、タイムゾーン変換をせず「入力された通りの
 * 現地時刻」として保存する。海外出張などで勤務日ごとに現地時刻(オフセット)が変わることを
 * 想定し、そのオフセットを attendance_days.utc_offset_minutes に記録する
 * (docs/03-architecture.md 3.4)。1回の編集で送られる日時は全て同じオフセットである
 * 必要がある(1勤務日に複数のオフセットは持たせない)。
 *
 * @implements CommandHandler<EditAttendanceDay>
 */
class EditAttendanceDayHandler implements CommandHandler
{
    public function __construct(
        private readonly EventStore $eventStore,
        private readonly AttendanceCalculator $calculator,
        private readonly AttendanceEditGuard $guard,
    ) {}

    public function handle(Command $command): AttendanceDay
    {
        assert($command instanceof EditAttendanceDay);

        $day = AttendanceDay::query()->findOrFail($command->attendanceDayId);

        $this->guard->assertMutable($day, $day->user_id, $day->work_date->toDateString());

        $day->utc_offset_minutes = $this->resolveOffsetMinutes($command, $day->utc_offset_minutes);

        $day->actual_start_at = $command->actualStartAt !== null
            ? LocalDateTime::splitOffset($command->actualStartAt)[0]
            : null;
        $day->actual_end_at = $command->actualEndAt !== null
            ? LocalDateTime::splitOffset($command->actualEndAt)[0]
            : null;
        $day->work_type = $command->workType;
        $day->note = $command->note;
        $day->source = AttendanceDaySource::MANUAL;
        if ($command->actualEndAt !== null) {
            $day->status = AttendanceDayStatus::CLOCKED_OUT;
        }
        $day->save();

        $day->breaks()->delete();
        foreach ($command->breaks as $break) {
            $day->breaks()->create([
                'break_start_at' => LocalDateTime::splitOffset($break['start'])[0],
                'break_end_at' => $break['end'] !== null ? LocalDateTime::splitOffset($break['end'])[0] : null,
            ]);
        }

        $this->eventStore->append(
            aggregateType: 'attendance_day',
            aggregateId: (string) $day->id,
            event: new AttendanceDayEdited(
                attendanceDayId: $day->id,
                editedByUserId: $command->editedByUserId,
                reason: $command->reason,
            ),
        );

        $calculation = $this->calculator->calculate($day->refresh()->load('breaks', 'shiftAssignment.workStyle'));

        $this->eventStore->append(
            aggregateType: 'attendance_day',
            aggregateId: (string) $day->id,
            event: new AttendanceDayCalculated(
                attendanceDayId: $day->id,
                calculation: $calculation,
            ),
        );

        return $day;
    }

    /**
     * 今回の編集で送られた日時(actual_start_at / actual_end_at / breaks[].start / breaks[].end)
     * のオフセットが全て一致することを検証し、その値を返す。1件も送られなかった場合は
     * 既存のオフセットを維持する。
     */
    private function resolveOffsetMinutes(EditAttendanceDay $command, int $existingOffsetMinutes): int
    {
        $inputs = array_filter([
            $command->actualStartAt,
            $command->actualEndAt,
            ...array_column($command->breaks, 'start'),
            ...array_column($command->breaks, 'end'),
        ], fn (?string $value) => $value !== null);

        $resolved = null;
        foreach ($inputs as $input) {
            [, $offsetMinutes] = LocalDateTime::splitOffset($input);
            if ($resolved !== null && $resolved !== $offsetMinutes) {
                throw new DomainRuleException('同一勤務日の時刻はすべて同じタイムゾーンオフセットで入力してください。');
            }
            $resolved = $offsetMinutes;
        }

        return $resolved ?? $existingOffsetMinutes;
    }
}
