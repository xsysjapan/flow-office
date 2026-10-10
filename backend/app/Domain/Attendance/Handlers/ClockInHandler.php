<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Commands\ClockIn;
use App\Domain\Attendance\Services\WebPunchDispatcher;
use App\Domain\Attendance\Support\AttendanceDayLeaves;
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
 * UC-A001: 出勤する。「今日」の判定と記録する時刻は、社員本人のタイムゾーンを基準にする。
 * Web画面固有の事前検証のみを行い、実際の記録・日次勤怠への反映は端末等と共通の
 * `RecordAttendancePunch`(UC-A012)に委譲する(docs/03-architecture.md 3.5)。
 *
 * @implements CommandHandler<ClockIn>
 */
class ClockInHandler implements CommandHandler
{
    public function __construct(
        private readonly WebPunchDispatcher $webPunchDispatcher,
        private readonly AttendanceDayLeaves $attendanceDayLeaves,
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

        // 全休の日は出勤できない(休暇ビューで判定。勤怠日のstatusや作業内容は見ない: 論点9)。
        if ($this->attendanceDayLeaves->isFullDayLeave($command->userId, $today->toDateString())) {
            throw new DomainRuleException('本日は全休の休暇のため出勤できません。');
        }

        return $this->webPunchDispatcher->dispatch(
            $day,
            $command->userId,
            $today->toDateString(),
            PunchType::CLOCK_IN,
            LocalDateTime::now($user->timezone),
        );
    }
}
