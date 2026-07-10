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
        private readonly AttendancePunchReconciler $reconciler,
        private readonly AttendanceCalculator $calculator,
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

        $this->syncAttendanceDayIfConsistent($user, $command->workDate);

        return $punch;
    }

    private function syncAttendanceDayIfConsistent(User $user, string $workDate): void
    {
        $userId = $user->id;
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
        $day->utc_offset_minutes = $reconciled['utc_offset_minutes'];
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
                actualStartAt: LocalDateTime::formatWithOffsetMinutes($day->actual_start_at, $day->utc_offset_minutes),
                actualEndAt: LocalDateTime::formatWithOffsetMinutes($day->actual_end_at, $day->utc_offset_minutes),
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
