<?php

namespace App\Domain\PaidLeave\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\PaidLeave\Aggregates\PaidLeaveGrantAggregate;
use App\Domain\PaidLeave\Commands\GrantPaidLeave;
use App\Jobs\SendNotificationJob;
use App\Models\PaidLeaveGrant;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * @implements CommandHandler<GrantPaidLeave>
 */
class GrantPaidLeaveHandler implements CommandHandler
{
    public function handle(Command $command): PaidLeaveGrant
    {
        assert($command instanceof GrantPaidLeave);

        $grantId = (string) Str::uuid();

        PaidLeaveGrantAggregate::retrieve($grantId)
            ->grant(
                userId: $command->userId,
                grantedOn: $command->grantedOn,
                expiresOn: $command->expiresOn,
                grantedDays: $command->grantedDays,
                grantReason: $command->grantReason,
            )
            ->persist();

        $grant = PaidLeaveGrant::query()->findOrFail($grantId);

        $user = User::find($command->userId);
        if ($user !== null) {
            SendNotificationJob::enqueue(
                recipient: $user,
                title: '有給付与',
                summary: "有給休暇が{$command->grantedDays}日付与されました(有効期限: {$command->expiresOn})。",
                detailUrl: null,
            );
        }

        return $grant;
    }
}
