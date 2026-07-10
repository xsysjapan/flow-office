<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Commands\EndBreak;
use App\Domain\Attendance\Events\AttendanceBreakEnded;
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
 * UC-A003: 休憩終了する。「今日」の判定と記録する時刻は、社員本人のタイムゾーンを基準にする。
 *
 * @implements CommandHandler<EndBreak>
 */
class EndBreakHandler implements CommandHandler
{
    public function __construct(private readonly EventStore $eventStore) {}

    public function handle(Command $command): AttendanceDay
    {
        assert($command instanceof EndBreak);

        $user = User::query()->findOrFail($command->userId);

        $day = AttendanceDay::query()
            ->where('user_id', $command->userId)
            ->whereDate('work_date', Carbon::today($user->timezone)->toDateString())
            ->first();

        if ($day === null || $day->status !== AttendanceDayStatus::ON_BREAK) {
            throw new DomainRuleException('休憩中の場合のみ休憩を終了できます。');
        }

        $break = $day->breaks()->whereNull('break_end_at')->latest('break_start_at')->first();
        if ($break === null) {
            throw new DomainRuleException('開始中の休憩が見つかりません。');
        }

        $break->break_end_at = LocalDateTime::now($user->timezone);
        $break->save();

        $day->status = AttendanceDayStatus::WORKING;
        $day->save();

        $this->eventStore->append(
            aggregateType: 'attendance_day',
            aggregateId: (string) $day->id,
            event: new AttendanceBreakEnded(
                attendanceDayId: $day->id,
                attendanceBreakId: $break->id,
                breakEndAt: LocalDateTime::formatWithOffsetMinutes($break->break_end_at, $day->utc_offset_minutes),
            ),
        );

        return $day;
    }
}
