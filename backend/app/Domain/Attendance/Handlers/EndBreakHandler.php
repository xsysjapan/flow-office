<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Commands\EndBreak;
use App\Domain\Attendance\Services\WebPunchDispatcher;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\AttendanceDay;
use App\Models\AttendanceDayStatus;
use App\Models\PunchType;
use App\Models\User;
use App\Support\LocalDateTime;
use Illuminate\Support\Carbon;

/**
 * UC-A003: 休憩終了する。「今日」の判定と記録する時刻は、社員本人のタイムゾーンを基準にする。
 * Web画面固有の事前検証のみを行い、実際の記録・日次勤怠への反映は端末等と共通の
 * `RecordAttendancePunch`(UC-A012)に委譲する(docs/03-architecture.md 3.5)。
 *
 * @implements CommandHandler<EndBreak>
 */
class EndBreakHandler implements CommandHandler
{
    public function __construct(private readonly WebPunchDispatcher $webPunchDispatcher) {}

    public function handle(Command $command): AttendanceDay
    {
        assert($command instanceof EndBreak);

        $user = User::query()->findOrFail($command->userId);
        $today = Carbon::today($user->timezone);

        $day = AttendanceDay::query()
            ->where('user_id', $command->userId)
            ->whereDate('work_date', $today->toDateString())
            ->first();

        if ($day === null || $day->status !== AttendanceDayStatus::ON_BREAK) {
            throw new DomainRuleException('休憩中の場合のみ休憩を終了できます。');
        }

        return $this->webPunchDispatcher->dispatch(
            $day,
            $command->userId,
            $today->toDateString(),
            PunchType::BREAK_END,
            LocalDateTime::now($user->timezone),
        );
    }
}
