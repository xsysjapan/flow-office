<?php

namespace App\Domain\PaidLeaveAccount\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\PaidLeaveAccount\Aggregates\PaidLeaveAccountAggregate;
use App\Domain\PaidLeaveAccount\Commands\ChangePaidLeaveGrantDate;

/**
 * @implements CommandHandler<ChangePaidLeaveGrantDate>
 */
class ChangePaidLeaveGrantDateHandler implements CommandHandler
{
    public function handle(Command $command): mixed
    {
        assert($command instanceof ChangePaidLeaveGrantDate);

        PaidLeaveAccountAggregate::retrieve($command->userId)
            ->changeGrantDate(
                grantId: $command->grantId,
                newGrantedOn: $command->newGrantedOn,
                reason: $command->reason,
                changedByUserId: $command->changedByUserId,
            )
            ->persist();
        return null;
    }
}
