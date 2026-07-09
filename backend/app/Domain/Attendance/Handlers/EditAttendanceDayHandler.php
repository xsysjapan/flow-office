<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Commands\EditAttendanceDay;
use App\Domain\Attendance\Events\AttendanceDayCalculated;
use App\Domain\Attendance\Events\AttendanceDayEdited;
use App\Domain\Attendance\Services\AttendanceCalculator;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\AttendanceDay;
use App\Models\AttendanceDaySource;
use App\Models\AttendanceDayStatus;
use App\Support\LocalDateTime;

/**
 * UC-A005: 日次勤怠を編集する。締め後(ロック後)は修正申請ワークフローを使う。
 * 入力される日時はオフセット付きISO8601を前提に、対象日の社員本人のタイムゾーンに
 * 変換してから保存する (docs/06-usecases-auth.md UC-003)。
 *
 * @implements CommandHandler<EditAttendanceDay>
 */
class EditAttendanceDayHandler implements CommandHandler
{
    public function __construct(
        private readonly EventStore $eventStore,
        private readonly AttendanceCalculator $calculator,
    ) {}

    public function handle(Command $command): AttendanceDay
    {
        assert($command instanceof EditAttendanceDay);

        $day = AttendanceDay::query()->with('user')->findOrFail($command->attendanceDayId);

        if ($day->isLocked()) {
            throw new DomainRuleException('締め後の勤怠は修正申請から変更してください。');
        }

        $timezone = $day->user->timezone;

        $day->actual_start_at = $command->actualStartAt !== null
            ? LocalDateTime::parse($command->actualStartAt, $timezone)
            : null;
        $day->actual_end_at = $command->actualEndAt !== null
            ? LocalDateTime::parse($command->actualEndAt, $timezone)
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
                'break_start_at' => LocalDateTime::parse($break['start'], $timezone),
                'break_end_at' => $break['end'] !== null ? LocalDateTime::parse($break['end'], $timezone) : null,
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
}
