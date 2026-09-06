<?php

namespace App\Domain\PaidLeaveAccount\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\PaidLeaveAccount\Aggregates\PaidLeaveAccountAggregate;
use App\Domain\PaidLeaveAccount\Commands\ChangePaidLeaveGrantAmount;

/**
 * @implements CommandHandler<ChangePaidLeaveGrantAmount>
 */
class ChangePaidLeaveGrantAmountHandler implements CommandHandler
{
    public function handle(Command $command): mixed
    {
        assert($command instanceof ChangePaidLeaveGrantAmount);

        PaidLeaveAccountAggregate::retrieve($command->userId)
            ->changeGrantAmount(
                grantId: $command->grantId,
                newGrantedDays: $command->newGrantedDays,
                reason: $command->reason,
                changedByUserId: $command->changedByUserId,
            )
            ->persist();
        return null;
    }
}
