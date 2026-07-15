<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Commands\StartBreak;
use App\Domain\Attendance\Events\AttendanceBreakStarted;
use App\Domain\Attendance\Services\LiveAttendancePunchRecorder;
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
 * UC-A002: 休憩開始する。「今日」の判定と記録する時刻は、社員本人のタイムゾーンを基準にする。
 *
 * @implements CommandHandler<StartBreak>
 */
class StartBreakHandler implements CommandHandler
{
    public function __construct(
        private readonly EventStore $eventStore,
        private readonly LiveAttendancePunchRecorder $punchRecorder,
    ) {}

    public function handle(Command $command): AttendanceDay
    {
        assert($command instanceof StartBreak);

        $user = User::query()->findOrFail($command->userId);
        $day = $this->findTodayWorkingDay($user);

        $break = $day->breaks()->create(['break_start_at' => LocalDateTime::now($user->timezone)]);
        $day->status = AttendanceDayStatus::ON_BREAK;
        $day->save();

        $this->punchRecorder->record($day, 'break_start', $break->break_start_at);

        $this->eventStore->append(
            aggregateType: 'attendance_day',
            aggregateId: (string) $day->id,
            event: new AttendanceBreakStarted(
                attendanceDayId: $day->id,
                attendanceBreakId: $break->id,
                breakStartAt: LocalDateTime::formatWithOffsetMinutes($break->break_start_at, $day->utc_offset_minutes),
            ),
        );

        return $day;
    }

    private function findTodayWorkingDay(User $user): AttendanceDay
    {
        $day = AttendanceDay::query()
            ->where('user_id', $user->id)
            ->whereDate('work_date', Carbon::today($user->timezone)->toDateString())
            ->first();

        if ($day === null || $day->status !== AttendanceDayStatus::WORKING) {
            throw new DomainRuleException('勤務中の場合のみ休憩を開始できます。');
        }

        return $day;
    }
}
