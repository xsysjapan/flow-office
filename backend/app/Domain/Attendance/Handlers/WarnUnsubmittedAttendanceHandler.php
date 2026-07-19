<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Commands\WarnUnsubmittedAttendance;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Jobs\SendNotificationJob;
use App\Models\AttendanceMonth;
use App\Models\AttendanceMonthStatus;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * UC-N001「勤怠未提出」: 前月分の勤怠がまだ提出されていない社員に警告する。
 * 提出期限(`system_settings.attendance_submission_deadline_day`、当月の日)を過ぎても
 * 前月分が未提出(差戻し中も含む)の在籍社員を対象に、解消するまで実行のたびに通知する
 * (状態を記録して警告を1回に絞る仕組みは持たない。docs/13-usecases-notification.md参照)。
 *
 * @implements CommandHandler<WarnUnsubmittedAttendance>
 */
class WarnUnsubmittedAttendanceHandler implements CommandHandler
{
    /**
     * @return int 警告を発行した件数
     */
    public function handle(Command $command): int
    {
        assert($command instanceof WarnUnsubmittedAttendance);

        $today = $command->asOf !== null ? Carbon::parse($command->asOf) : Carbon::today();
        $deadlineDay = SystemSetting::current()->attendance_submission_deadline_day;

        if ($today->day < $deadlineDay) {
            return 0;
        }

        $targetYearMonth = $today->copy()->subMonthNoOverflow()->format('Y-m');

        $submittedUserIds = AttendanceMonth::query()
            ->where('year_month', $targetYearMonth)
            ->whereIn('status', [AttendanceMonthStatus::SUBMITTED, AttendanceMonthStatus::APPROVED, AttendanceMonthStatus::CLOSED])
            ->pluck('user_id');

        $unsubmittedUsers = User::query()
            ->where('employment_status', 'active')
            ->whereNotIn('id', $submittedUserIds)
            ->get(['id', 'name', 'email']);

        foreach ($unsubmittedUsers as $user) {
            SendNotificationJob::enqueue(
                recipient: $user,
                title: '勤怠未提出',
                summary: "{$user->name}さんの{$targetYearMonth}分の勤怠がまだ提出されていません。",
                detailUrl: null,
            );
        }

        return $unsubmittedUsers->count();
    }
}
