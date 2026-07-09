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
use App\Models\AttendanceDayStatus;

/**
 * UC-A005: 日次勤怠を編集する。締め後(ロック後)は修正申請ワークフローを使う。
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

        $day = AttendanceDay::query()->findOrFail($command->attendanceDayId);

        if ($day->isLocked()) {
            throw new DomainRuleException('締め後の勤怠は修正申請から変更してください。');
        }

        $day->actual_start_at = $command->actualStartAt;
        $day->actual_end_at = $command->actualEndAt;
        $day->work_type = $command->workType;
        $day->note = $command->note;
        if ($command->actualEndAt !== null) {
            $day->status = AttendanceDayStatus::CLOCKED_OUT;
        }
        $day->save();

        $day->breaks()->delete();
        foreach ($command->breaks as $break) {
            $day->breaks()->create([
                'break_start_at' => $break['start'],
                'break_end_at' => $break['end'],
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
