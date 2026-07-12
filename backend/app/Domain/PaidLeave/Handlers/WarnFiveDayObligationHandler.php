<?php

namespace App\Domain\PaidLeave\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Domain\PaidLeave\Commands\WarnFiveDayObligation;
use App\Domain\PaidLeave\Events\PaidLeaveWarningRaised;
use App\Jobs\SendTeamsNotificationJob;
use App\Models\PaidLeaveGrant;
use Illuminate\Support\Carbon;

/**
 * UC-P006: 年5日取得義務を警告する。年10日以上付与された付与単位ごとに、取得義務期間
 * (付与日から1年)の終了が近づいても取得日数が5日未満の場合に警告する。「承認者」は
 * 有給申請ごとに都度指定されるため固定の宛先を持たず、社員本人と管理部(Teams通知チャンネル)
 * へ通知する。
 *
 * @implements CommandHandler<WarnFiveDayObligation>
 */
class WarnFiveDayObligationHandler implements CommandHandler
{
    private const MINIMUM_GRANT_DAYS_FOR_OBLIGATION = 10;

    private const REQUIRED_USED_DAYS = 5;

    private const WARNING_WINDOW_DAYS = 60;

    public function __construct(private readonly EventStore $eventStore) {}

    /**
     * @return int 警告を発行した件数
     */
    public function handle(Command $command): int
    {
        assert($command instanceof WarnFiveDayObligation);

        $today = $command->asOf !== null ? Carbon::parse($command->asOf) : Carbon::today();
        $warnedCount = 0;

        $grants = PaidLeaveGrant::query()
            ->with('user', 'usages')
            ->where('granted_days', '>=', self::MINIMUM_GRANT_DAYS_FOR_OBLIGATION)
            ->whereNull('five_day_obligation_warned_at')
            ->get();

        foreach ($grants as $grant) {
            $obligationDeadline = $grant->granted_on->copy()->addYear();
            $daysUntilDeadline = $today->diffInDays($obligationDeadline, false);

            if ($daysUntilDeadline < 0 || $daysUntilDeadline > self::WARNING_WINDOW_DAYS) {
                continue;
            }

            $usedDays = (float) $grant->usages->sum('used_days');
            if ($usedDays >= self::REQUIRED_USED_DAYS) {
                continue;
            }

            $message = "{$grant->user->name}さんは年5日の有給取得義務(期限: ".
                "{$obligationDeadline->toDateString()})に対し、現在{$usedDays}日しか取得していません。";

            SendTeamsNotificationJob::enqueue(
                title: '有給休暇 年5日取得義務の警告',
                summary: $message,
                detailUrl: null,
            );

            $grant->five_day_obligation_warned_at = Carbon::now();
            $grant->save();

            $this->eventStore->append(
                aggregateType: 'paid_leave_grant',
                aggregateId: (string) $grant->id,
                event: new PaidLeaveWarningRaised(
                    paidLeaveGrantId: $grant->id,
                    userId: $grant->user_id,
                    warningType: 'five_day_obligation',
                    message: $message,
                ),
            );

            $warnedCount++;
        }

        return $warnedCount;
    }
}
