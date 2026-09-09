<?php

namespace App\Domain\PaidLeaveAccount\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\PaidLeaveAccount\Aggregates\PaidLeaveAccountAggregate;
use App\Domain\PaidLeaveAccount\Commands\ChangePaidLeaveGrantExpiry;

/**
 * @implements CommandHandler<ChangePaidLeaveGrantExpiry>
 */
class ChangePaidLeaveGrantExpiryHandler implements CommandHandler
{
    public function handle(Command $command): mixed
    {
        assert($command instanceof ChangePaidLeaveGrantExpiry);

        PaidLeaveAccountAggregate::retrieve($command->userId)
            ->changeGrantExpiry(
                grantId: $command->grantId,
                newExpiresOn: $command->newExpiresOn,
                reason: $command->reason,
                changedByUserId: $command->changedByUserId,
            )
            ->persist();
        return null;
    }
}
