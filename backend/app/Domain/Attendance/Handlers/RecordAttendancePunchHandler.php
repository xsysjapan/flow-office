<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Commands\RecordAttendancePunch;
use App\Domain\Attendance\Events\AttendanceDayCalculated;
use App\Domain\Attendance\Events\AttendanceDaySyncedFromPunches;
use App\Domain\Attendance\Events\AttendancePunchRecorded;
use App\Domain\Attendance\Services\AttendanceCalculator;
use App\Domain\Attendance\Services\AttendancePunchReconciler;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Models\AttendanceDay;
use App\Models\AttendanceDaySource;
use App\Models\AttendanceDayStatus;
use App\Models\AttendancePunch;
use App\Models\EmployeeShiftAssignment;

/**
 * UC-A012: 打刻ログを記録する。矛盾があっても記録は必ず成功させ、
 * 矛盾なく1日分の勤務として組み立てられる場合のみ attendance_days に反映する。
 *
 * @implements CommandHandler<RecordAttendancePunch>
 */
class RecordAttendancePunchHandler implements CommandHandler
{
    public function __construct(
        private readonly EventStore $eventStore,
        private readonly AttendancePunchReconciler $reconciler,
        private readonly AttendanceCalculator $calculator,
    ) {}

    public function handle(Command $command): AttendancePunch
    {
        assert($command instanceof RecordAttendancePunch);

        $punch = AttendancePunch::query()->create([
            'user_id' => $command->userId,
            'work_date' => $command->workDate,
            'punch_type' => $command->punchType,
            'punched_at' => $command->punchedAt,
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
                punchedAt: $command->punchedAt,
                source: $command->source,
            ),
        );

        $this->syncAttendanceDayIfConsistent($command->userId, $command->workDate);

        return $punch;
    }

    private function syncAttendanceDayIfConsistent(int $userId, string $workDate): void
    {
        $punches = AttendancePunch::query()
            ->where('user_id', $userId)
            ->whereDate('work_date', $workDate)
            ->orderBy('punched_at')
            ->get();

        $reconciled = $this->reconciler->reconcile($punches);
        if ($reconciled === null) {
            return;
        }

        $day = AttendanceDay::query()
            ->where('user_id', $userId)
            ->whereDate('work_date', $workDate)
            ->first();

        if ($day !== null && ($day->source !== AttendanceDaySource::PUNCH || $day->isLocked())) {
            // 画面からの操作・日次編集で既に確定した日、および締め後にロックされた日は、
            // 打刻ログで上書きしない(締め後の修正は修正申請ワークフローを使う)。
            return;
        }

        if ($day === null) {
            $shiftAssignment = EmployeeShiftAssignment::query()
                ->where('user_id', $userId)
                ->whereDate('work_date', $workDate)
                ->first();

            $day = AttendanceDay::query()->create([
                'user_id' => $userId,
                'work_date' => $workDate,
                'shift_assignment_id' => $shiftAssignment?->id,
                'status' => AttendanceDayStatus::NOT_STARTED,
                'source' => AttendanceDaySource::PUNCH,
            ]);
        }

        $day->actual_start_at = $reconciled['clock_in'];
        $day->actual_end_at = $reconciled['clock_out'];
        $day->status = AttendanceDayStatus::CLOCKED_OUT;
        $day->source = AttendanceDaySource::PUNCH;
        $day->save();

        $day->breaks()->delete();
        foreach ($reconciled['breaks'] as $break) {
            $day->breaks()->create([
                'break_start_at' => $break['start'],
                'break_end_at' => $break['end'],
            ]);
        }

        $this->eventStore->append(
            aggregateType: 'attendance_day',
            aggregateId: (string) $day->id,
            event: new AttendanceDaySyncedFromPunches(
                attendanceDayId: $day->id,
                actualStartAt: $day->actual_start_at->toIso8601String(),
                actualEndAt: $day->actual_end_at->toIso8601String(),
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
    }
}
