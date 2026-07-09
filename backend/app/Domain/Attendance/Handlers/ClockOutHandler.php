<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Commands\ClockOut;
use App\Domain\Attendance\Events\AttendanceClockedOut;
use App\Domain\Attendance\Events\AttendanceDayCalculated;
use App\Domain\Attendance\Services\AttendanceCalculator;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\AttendanceDay;
use App\Models\AttendanceDayStatus;
use Illuminate\Support\Carbon;

/**
 * UC-A004: 退勤する。
 *
 * @implements CommandHandler<ClockOut>
 */
class ClockOutHandler implements CommandHandler
{
    public function __construct(
        private readonly EventStore $eventStore,
        private readonly AttendanceCalculator $calculator,
    ) {}

    public function handle(Command $command): AttendanceDay
    {
        assert($command instanceof ClockOut);

        $day = AttendanceDay::query()
            ->where('user_id', $command->userId)
            ->whereDate('work_date', Carbon::today()->toDateString())
            ->first();

        if ($day === null || $day->status !== AttendanceDayStatus::WORKING) {
            throw new DomainRuleException('勤務中の場合のみ退勤できます(休憩中は休憩終了後に退勤してください)。');
        }

        $day->actual_end_at = Carbon::now();
        $day->status = AttendanceDayStatus::CLOCKED_OUT;
        $day->save();

        $this->eventStore->append(
            aggregateType: 'attendance_day',
            aggregateId: (string) $day->id,
            event: new AttendanceClockedOut(
                attendanceDayId: $day->id,
                actualEndAt: $day->actual_end_at->toIso8601String(),
            ),
        );

        $calculation = $this->calculator->calculate($day->refresh());

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
