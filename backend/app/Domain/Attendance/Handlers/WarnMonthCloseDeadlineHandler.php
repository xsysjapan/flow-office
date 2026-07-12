<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Commands\WarnMonthCloseDeadline;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Jobs\SendTeamsNotificationJob;
use App\Models\AttendanceMonth;
use App\Models\AttendanceMonthStatus;
use App\Models\SystemSetting;
use Illuminate\Support\Carbon;

/**
 * UC-N001「月次締め前警告」: 前月分の月次勤怠がまだ締められていない場合、締め期限
 * (`system_settings.attendance_month_close_deadline_day`、当月の日)が近づいたら管理部へ警告する。
 * 提出済み(submitted)・承認済み(approved)のいずれもまだ「締め」(closed)ではないため対象になる。
 *
 * @implements CommandHandler<WarnMonthCloseDeadline>
 */
class WarnMonthCloseDeadlineHandler implements CommandHandler
{
    /** 締め期限の何日前から警告するか。 */
    private const WARNING_WINDOW_DAYS = 3;

    /**
     * @return int 警告を発行した件数
     */
    public function handle(Command $command): int
    {
        assert($command instanceof WarnMonthCloseDeadline);

        $today = $command->asOf !== null ? Carbon::parse($command->asOf) : Carbon::today();
        $deadlineDay = SystemSetting::current()->attendance_month_close_deadline_day;
        $warningStartDay = max(1, $deadlineDay - self::WARNING_WINDOW_DAYS);

        if ($today->day < $warningStartDay || $today->day > $deadlineDay) {
            return 0;
        }

        $targetYearMonth = $today->copy()->subMonthNoOverflow()->format('Y-m');

        $notClosedCount = AttendanceMonth::query()
            ->where('year_month', $targetYearMonth)
            ->whereIn('status', [AttendanceMonthStatus::SUBMITTED, AttendanceMonthStatus::APPROVED])
            ->count();

        if ($notClosedCount === 0) {
            return 0;
        }

        SendTeamsNotificationJob::enqueue(
            title: '月次締め前警告',
            summary: "{$targetYearMonth}分の月次勤怠が{$notClosedCount}件、締め切り(当月{$deadlineDay}日)".
                'までにまだ締められていません。',
            detailUrl: null,
        );

        return $notClosedCount;
    }
}
