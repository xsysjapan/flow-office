<?php

namespace App\Domain\SpecialLeaveAccount\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\SpecialLeaveAccount\Aggregates\SpecialLeaveAccountAggregate;
use App\Domain\SpecialLeaveAccount\Commands\RevokeSpecialLeaveAccountGrant;

/**
 * @implements CommandHandler<RevokeSpecialLeaveAccountGrant>
 */
class RevokeSpecialLeaveAccountGrantHandler implements CommandHandler
{
    public function handle(Command $command): mixed
    {
        assert($command instanceof RevokeSpecialLeaveAccountGrant);

        SpecialLeaveAccountAggregate::retrieve(SpecialLeaveAccountAggregate::streamIdFor($command->userId))
            ->revokeGrant($command->grantId, $command->revokedByUserId, $command->reason)
            ->persist();

        return null;
    }
}
