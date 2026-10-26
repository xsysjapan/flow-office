<?php

namespace App\Domain\SpecialLeaveAccount\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\SpecialLeaveAccount\Aggregates\SpecialLeaveAccountAggregate;
use App\Domain\SpecialLeaveAccount\Commands\ChangeSpecialLeaveGrantAmount;

/**
 * @implements CommandHandler<ChangeSpecialLeaveGrantAmount>
 */
class ChangeSpecialLeaveGrantAmountHandler implements CommandHandler
{
    public function handle(Command $command): mixed
    {
        assert($command instanceof ChangeSpecialLeaveGrantAmount);

        SpecialLeaveAccountAggregate::retrieve(SpecialLeaveAccountAggregate::streamIdFor($command->userId))
            ->changeGrantAmount($command->grantId, $command->newGrantedDays, $command->changedByUserId, $command->reason)
            ->persist();

        return null;
    }
}
