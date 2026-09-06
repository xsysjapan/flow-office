<?php

namespace App\Domain\PaidLeaveAccount\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\PaidLeaveAccount\Aggregates\PaidLeaveAccountAggregate;
use App\Domain\PaidLeaveAccount\Commands\ConfirmPaidLeaveUsage;

/**
 * @implements CommandHandler<ConfirmPaidLeaveUsage>
 */
class ConfirmPaidLeaveUsageHandler implements CommandHandler
{
    public function handle(Command $command): mixed
    {
        assert($command instanceof ConfirmPaidLeaveUsage);

        PaidLeaveAccountAggregate::retrieve($command->userId)
            ->confirmUsage(
                usageId: $command->usageId,
                confirmedByUserId: $command->confirmedByUserId,
            )
            ->persist();
        return null;
    }
}
