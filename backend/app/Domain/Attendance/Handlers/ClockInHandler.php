<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Commands\ClockIn;
use App\Domain\Attendance\Events\AttendanceClockedIn;
use App\Domain\Attendance\Services\LiveAttendancePunchRecorder;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\AttendanceDay;
use App\Models\AttendanceDaySource;
use App\Models\AttendanceDayStatus;
use App\Models\EmployeeShiftAssignment;
use App\Models\User;
use App\Support\LocalDateTime;
use Illuminate\Support\Carbon;

/**
 * UC-A001: 出勤する。「今日」の判定と記録する時刻は、社員本人のタイムゾーンを基準にする。
 * このときの現在のUTCオフセットを attendance_days.utc_offset_minutes に記録する
 * (docs/03-architecture.md 3.4)。
 *
 * @implements CommandHandler<ClockIn>
 */
class ClockInHandler implements CommandHandler
{
    public function __construct(
        private readonly EventStore $eventStore,
        private readonly LiveAttendancePunchRecorder $punchRecorder,
    ) {}

    public function handle(Command $command): AttendanceDay
    {
        assert($command instanceof ClockIn);

        $user = User::query()->findOrFail($command->userId);
        $today = Carbon::today($user->timezone);

        $day = AttendanceDay::query()
            ->where('user_id', $command->userId)
            ->whereDate('work_date', $today->toDateString())
            ->first();

        if ($day !== null && $day->status !== AttendanceDayStatus::NOT_STARTED) {
            throw new DomainRuleException('本日は既に出勤処理済みです。');
        }

        if ($day === null) {
            $shiftAssignment = EmployeeShiftAssignment::query()
                ->where('user_id', $command->userId)
                ->whereDate('work_date', $today->toDateString())
                ->first();

            $day = AttendanceDay::query()->create([
                'user_id' => $command->userId,
                'work_date' => $today->toDateString(),
                'shift_assignment_id' => $shiftAssignment?->id,
                'status' => AttendanceDayStatus::NOT_STARTED,
                'source' => AttendanceDaySource::LIVE,
            ]);
        }

        $now = LocalDateTime::now($user->timezone);
        $day->actual_start_at = $now;
        $day->utc_offset_minutes = $now->utcOffset();
        $day->status = AttendanceDayStatus::WORKING;
        $day->source = AttendanceDaySource::LIVE;
        $day->save();

        $this->punchRecorder->record($day, 'clock_in', $now);

        $this->eventStore->append(
            aggregateType: 'attendance_day',
            aggregateId: (string) $day->id,
            event: new AttendanceClockedIn(
                attendanceDayId: $day->id,
                userId: $command->userId,
                actualStartAt: LocalDateTime::formatWithOffsetMinutes($day->actual_start_at, $day->utc_offset_minutes),
            ),
        );

        return $day;
    }
}
