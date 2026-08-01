<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Commands\WarnMonthCloseDeadline;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\Notification\NotificationRecipients;
use App\Jobs\SendNotificationJob;
use App\Models\AttendanceMonth;
use App\Models\AttendanceMonthStatus;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\DailyBatchTimezoneGroups;

/**
 * UC-N001「月次締め前警告」: 前月分の月次勤怠がまだ締められていない場合、締め期限
 * (`system_settings.attendance_month_close_deadline_day`、当月の日)が近づいたら管理部
 * (ADMIN/GENERAL_AFFAIRS_STAFFロールの各ユーザー)へ警告する。
 * 提出済み(submitted)・承認済み(approved)のいずれもまだ「締め」(closed)ではないため対象になる。
 * 対象者の利用開始日が未設定、または利用開始日・入社日が対象月より後の場合は集計対象外とする。
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

        $systemSetting = SystemSetting::current();
        $defaultTimezone = $systemSetting->default_timezone;
        $deadlineDay = $systemSetting->attendance_month_close_deadline_day;
        $warningStartDay = max(1, $deadlineDay - self::WARNING_WINDOW_DAYS);

        $activeUsersQuery = User::query()->where('employment_status', 'active');
        $notClosedCount = 0;

        // `asOf`未指定(cronからの実運用)の場合、会社既定のタイムゾーンではなく社員本人の
        // `users.timezone`基準で「今日」を判定する。タイムゾーンごとに対象社員を絞り、
        // それぞれの「今日」で締め日・対象月の判定を行う(DailyBatchTimezoneGroups参照)。
        foreach (DailyBatchTimezoneGroups::resolve($command->asOf, $activeUsersQuery) as $group) {
            $today = $group['today'];

            if ($today->day < $warningStartDay || $today->day > $deadlineDay) {
                continue;
            }

            $targetMonth = $today->copy()->subMonthNoOverflow();
            $targetYearMonth = $targetMonth->format('Y-m');
            // Carbon::createFromFormat('Y-m', ...)は日付部分を「実行時点の日」で補完するため、
            // 対象月より日数が少ない月(例: 6月)を31日に実行すると7月扱いに繰り上がってしまう。
            // 文字列を再パースせず$targetMonthから直接endOfMonth()を求めることでこれを避ける。
            $targetMonthEnd = $targetMonth->copy()->endOfMonth()->toDateString();

            $groupNotClosedCount = AttendanceMonth::query()
                ->where('year_month', $targetYearMonth)
                ->whereIn('status', [AttendanceMonthStatus::SUBMITTED, AttendanceMonthStatus::APPROVED])
                ->whereHas('user', function ($query) use ($targetMonthEnd, $group, $defaultTimezone) {
                    $query->whereNotNull('usage_start_date')
                        ->whereDate('usage_start_date', '<=', $targetMonthEnd)
                        ->where(fn ($q) => $q->whereNull('hire_date')->orWhereDate('hire_date', '<=', $targetMonthEnd))
                        ->where(DailyBatchTimezoneGroups::constraint($group['timezone'], $defaultTimezone));
                })
                ->count();

            if ($groupNotClosedCount === 0) {
                continue;
            }

            $summary = "{$targetYearMonth}分の月次勤怠が{$groupNotClosedCount}件、締め切り(当月{$deadlineDay}日)".
                'までにまだ締められていません。';

            foreach (NotificationRecipients::byRoles([Role::ADMIN, Role::GENERAL_AFFAIRS_STAFF]) as $recipient) {
                SendNotificationJob::enqueue(
                    recipient: $recipient,
                    title: '月次締め前警告',
                    summary: $summary,
                    detailUrl: null,
                );
            }

            $notClosedCount += $groupNotClosedCount;
        }

        return $notClosedCount;
    }
}
