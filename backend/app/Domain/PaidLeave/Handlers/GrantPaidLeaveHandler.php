<?php

namespace App\Domain\PaidLeave\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Domain\PaidLeave\Commands\GrantPaidLeave;
use App\Domain\PaidLeave\Events\PaidLeaveGranted;
use App\Jobs\SendTeamsNotificationJob;
use App\Models\PaidLeaveGrant;

/**
 * @implements CommandHandler<GrantPaidLeave>
 */
class GrantPaidLeaveHandler implements CommandHandler
{
    public function __construct(private readonly EventStore $eventStore) {}

    public function handle(Command $command): PaidLeaveGrant
    {
        assert($command instanceof GrantPaidLeave);

        $grant = PaidLeaveGrant::query()->create([
            'user_id' => $command->userId,
            'granted_on' => $command->grantedOn,
            'expires_on' => $command->expiresOn,
            'granted_days' => $command->grantedDays,
            'used_days' => 0,
            'remaining_days' => $command->grantedDays,
            'grant_reason' => $command->grantReason,
        ]);

        $this->eventStore->append(
            aggregateType: 'paid_leave_grant',
            aggregateId: (string) $grant->id,
            event: new PaidLeaveGranted(
                paidLeaveGrantId: $grant->id,
                userId: $command->userId,
                grantedOn: $command->grantedOn,
                expiresOn: $command->expiresOn,
                grantedDays: $command->grantedDays,
            ),
        );

        SendTeamsNotificationJob::enqueue(
            title: '有給付与',
            summary: "有給休暇が{$command->grantedDays}日付与されました(有効期限: {$command->expiresOn})。",
            detailUrl: null,
        );

        return $grant;
    }
}
