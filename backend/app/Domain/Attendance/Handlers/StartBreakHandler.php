<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Commands\StartBreak;
use App\Domain\Attendance\Events\AttendanceBreakStarted;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\AttendanceDay;
use App\Models\AttendanceDayStatus;
use Illuminate\Support\Carbon;

/**
 * UC-A002: 休憩開始する。
 *
 * @implements CommandHandler<StartBreak>
 */
class StartBreakHandler implements CommandHandler
{
    public function __construct(private readonly EventStore $eventStore) {}

    public function handle(Command $command): AttendanceDay
    {
        assert($command instanceof StartBreak);

        $day = $this->findTodayWorkingDay($command->userId);

        $break = $day->breaks()->create(['break_start_at' => Carbon::now()]);
        $day->status = AttendanceDayStatus::ON_BREAK;
        $day->save();

        $this->eventStore->append(
            aggregateType: 'attendance_day',
            aggregateId: (string) $day->id,
            event: new AttendanceBreakStarted(
                attendanceDayId: $day->id,
                attendanceBreakId: $break->id,
                breakStartAt: $break->break_start_at->toIso8601String(),
            ),
        );

        return $day;
    }

    private function findTodayWorkingDay(int $userId): AttendanceDay
    {
        $day = AttendanceDay::query()
            ->where('user_id', $userId)
            ->whereDate('work_date', Carbon::today()->toDateString())
            ->first();

        if ($day === null || $day->status !== AttendanceDayStatus::WORKING) {
            throw new DomainRuleException('勤務中の場合のみ休憩を開始できます。');
        }

        return $day;
    }
}
