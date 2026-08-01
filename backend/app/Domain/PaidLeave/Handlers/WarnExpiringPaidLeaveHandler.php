<?php

namespace App\Domain\PaidLeave\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\PaidLeave\Aggregates\PaidLeaveGrantAggregate;
use App\Domain\PaidLeave\Commands\WarnExpiringPaidLeave;
use App\Jobs\SendNotificationJob;
use App\Models\PaidLeaveGrant;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\DailyBatchTimezoneGroups;

/**
 * UC-P005: 有給消滅警告を出す。有効期限90日以内・残日数ありの付与を対象に、
 * 本人へ通知する。同一付与に重複して通知しないよう
 * `paid_leave_grants.expiry_warned_at` で警告済みを記録する。
 *
 * @implements CommandHandler<WarnExpiringPaidLeave>
 */
class WarnExpiringPaidLeaveHandler implements CommandHandler
{
    private const WARNING_WINDOW_DAYS = 90;

    /**
     * @return int 警告を発行した件数
     */
    public function handle(Command $command): int
    {
        assert($command instanceof WarnExpiringPaidLeave);

        $defaultTimezone = SystemSetting::current()->default_timezone;
        $warnedCount = 0;

        // `asOf`未指定(cronからの実運用)の場合、会社既定のタイムゾーンではなく社員本人の
        // `users.timezone`基準で「今日」を判定する。タイムゾーンごとの分類対象は既存コードに
        // 合わせて在籍中の社員全体とする(DailyBatchTimezoneGroups参照)。
        $activeUsersQuery = User::query()->where('employment_status', 'active');

        foreach (DailyBatchTimezoneGroups::resolve($command->asOf, $activeUsersQuery) as $group) {
            $today = $group['today'];
            $threshold = $today->copy()->addDays(self::WARNING_WINDOW_DAYS);

            $grants = PaidLeaveGrant::query()
                ->with('user')
                ->where('remaining_days', '>', 0)
                ->whereNull('expiry_warned_at')
                ->whereDate('expires_on', '>=', $today->toDateString())
                ->whereDate('expires_on', '<=', $threshold->toDateString())
                ->whereHas('user', DailyBatchTimezoneGroups::constraint($group['timezone'], $defaultTimezone))
                ->get();

            foreach ($grants as $grant) {
                $message = "{$grant->user->name}さんの有給休暇 {$grant->remaining_days}日が".
                    "{$grant->expires_on->toDateString()}に失効します。";

                SendNotificationJob::enqueue(
                    recipient: $grant->user,
                    title: '有給休暇の失効警告',
                    summary: $message,
                    detailUrl: null,
                );

                PaidLeaveGrantAggregate::retrieve($grant->id)
                    ->raiseWarning($grant->user_id, 'expiry', $message)
                    ->persist();
            }

            $warnedCount += $grants->count();
        }

        return $warnedCount;
    }
}
