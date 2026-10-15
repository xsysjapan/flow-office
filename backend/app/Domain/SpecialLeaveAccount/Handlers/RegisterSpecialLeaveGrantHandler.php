<?php

namespace App\Domain\SpecialLeaveAccount\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\SpecialLeaveAccount\Aggregates\SpecialLeaveAccountAggregate;
use App\Domain\SpecialLeaveAccount\Commands\RegisterSpecialLeaveGrant;

/**
 * @implements CommandHandler<RegisterSpecialLeaveGrant>
 */
class RegisterSpecialLeaveGrantHandler implements CommandHandler
{
    public function handle(Command $command): string
    {
        assert($command instanceof RegisterSpecialLeaveGrant);

        SpecialLeaveAccountAggregate::retrieve(SpecialLeaveAccountAggregate::streamIdFor($command->userId))
            ->registerGrant(
                grantId: $command->grantId,
                specialLeaveTypeId: $command->specialLeaveTypeId,
                grantedOn: $command->grantedOn,
                expiresOn: $command->expiresOn,
                grantedDays: $command->grantedDays,
                grantReason: $command->grantReason,
                userId: $command->userId,
            )
            ->persist();

        return $command->grantId;
    }
}
