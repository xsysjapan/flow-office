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
use App\Models\User;
use App\Support\LocalDateTime;
use Illuminate\Support\Carbon;

/**
 * UC-A004: 退勤する。「今日」の判定と記録する時刻は、社員本人のタイムゾーンを基準にする。
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

        $user = User::query()->findOrFail($command->userId);

        $day = AttendanceDay::query()
            ->where('user_id', $command->userId)
            ->whereDate('work_date', Carbon::today($user->timezone)->toDateString())
            ->first();

        if ($day === null || $day->status !== AttendanceDayStatus::WORKING) {
            throw new DomainRuleException('勤務中の場合のみ退勤できます(休憩中は休憩終了後に退勤してください)。');
        }

        $day->actual_end_at = LocalDateTime::now($user->timezone);
        $day->status = AttendanceDayStatus::CLOCKED_OUT;
        $day->save();

        $this->eventStore->append(
            aggregateType: 'attendance_day',
            aggregateId: (string) $day->id,
            event: new AttendanceClockedOut(
                attendanceDayId: $day->id,
                actualEndAt: LocalDateTime::formatWithOffsetMinutes($day->actual_end_at, $day->utc_offset_minutes),
            ),
        );

        $calculation = $this->calculator->calculate($day->refresh()->load('breaks', 'leaveSegments', 'paidLeaveUsages', 'shiftAssignment.workStyle'));

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
