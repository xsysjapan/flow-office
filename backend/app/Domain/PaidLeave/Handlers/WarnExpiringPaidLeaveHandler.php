<?php

namespace App\Domain\PaidLeave\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Domain\PaidLeave\Commands\WarnExpiringPaidLeave;
use App\Domain\PaidLeave\Events\PaidLeaveWarningRaised;
use App\Jobs\SendNotificationJob;
use App\Models\PaidLeaveGrant;
use Illuminate\Support\Carbon;

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

    public function __construct(private readonly EventStore $eventStore) {}

    /**
     * @return int 警告を発行した件数
     */
    public function handle(Command $command): int
    {
        assert($command instanceof WarnExpiringPaidLeave);

        $today = $command->asOf !== null ? Carbon::parse($command->asOf) : Carbon::today();
        $threshold = $today->copy()->addDays(self::WARNING_WINDOW_DAYS);

        $grants = PaidLeaveGrant::query()
            ->with('user')
            ->where('remaining_days', '>', 0)
            ->whereNull('expiry_warned_at')
            ->whereDate('expires_on', '>=', $today->toDateString())
            ->whereDate('expires_on', '<=', $threshold->toDateString())
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

            $grant->expiry_warned_at = Carbon::now();
            $grant->save();

            $this->eventStore->append(
                aggregateType: 'paid_leave_grant',
                aggregateId: (string) $grant->id,
                event: new PaidLeaveWarningRaised(
                    paidLeaveGrantId: $grant->id,
                    userId: $grant->user_id,
                    warningType: 'expiry',
                    message: $message,
                ),
            );
        }

        return $grants->count();
    }
}
