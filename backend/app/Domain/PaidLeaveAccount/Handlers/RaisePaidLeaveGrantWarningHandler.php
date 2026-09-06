<?php

namespace App\Domain\PaidLeaveAccount\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\PaidLeaveAccount\Aggregates\PaidLeaveAccountAggregate;
use App\Domain\PaidLeaveAccount\Commands\RaisePaidLeaveGrantWarning;

/**
 * @implements CommandHandler<RaisePaidLeaveGrantWarning>
 */
class RaisePaidLeaveGrantWarningHandler implements CommandHandler
{
    public function handle(Command $command): mixed
    {
        assert($command instanceof RaisePaidLeaveGrantWarning);

        PaidLeaveAccountAggregate::retrieve($command->userId)
            ->raiseGrantWarning(
                grantId: $command->grantId,
                warningType: $command->warningType,
                message: $command->message,
            )
            ->persist();

        return null;
    }
}
