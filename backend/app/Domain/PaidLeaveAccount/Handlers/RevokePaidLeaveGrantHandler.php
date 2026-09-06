<?php

namespace App\Domain\PaidLeaveAccount\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\PaidLeaveAccount\Aggregates\PaidLeaveAccountAggregate;
use App\Domain\PaidLeaveAccount\Commands\RevokePaidLeaveGrant;

/**
 * @implements CommandHandler<RevokePaidLeaveGrant>
 */
class RevokePaidLeaveGrantHandler implements CommandHandler
{
    public function handle(Command $command): mixed
    {
        assert($command instanceof RevokePaidLeaveGrant);

        PaidLeaveAccountAggregate::retrieve($command->userId)
            ->revokeGrant(
                grantId: $command->grantId,
                revokedByUserId: $command->revokedByUserId,
                reason: $command->reason,
            )
            ->persist();
        return null;
    }
}
