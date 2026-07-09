<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Commands\ClockIn;
use App\Domain\Attendance\Events\AttendanceClockedIn;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\AttendanceDay;
use App\Models\AttendanceDaySource;
use App\Models\AttendanceDayStatus;
use App\Models\EmployeeShiftAssignment;
use Illuminate\Support\Carbon;

/**
 * UC-A001: 出勤する。
 *
 * @implements CommandHandler<ClockIn>
 */
class ClockInHandler implements CommandHandler
{
    public function __construct(private readonly EventStore $eventStore) {}

    public function handle(Command $command): AttendanceDay
    {
        assert($command instanceof ClockIn);

        $today = Carbon::today();

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

        $day->actual_start_at = Carbon::now();
        $day->status = AttendanceDayStatus::WORKING;
        $day->source = AttendanceDaySource::LIVE;
        $day->save();

        $this->eventStore->append(
            aggregateType: 'attendance_day',
            aggregateId: (string) $day->id,
            event: new AttendanceClockedIn(
                attendanceDayId: $day->id,
                userId: $command->userId,
                actualStartAt: $day->actual_start_at->toIso8601String(),
            ),
        );

        return $day;
    }
}
