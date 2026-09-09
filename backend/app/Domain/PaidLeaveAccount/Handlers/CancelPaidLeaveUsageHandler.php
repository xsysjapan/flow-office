<?php

namespace App\Domain\PaidLeaveAccount\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\PaidLeaveAccount\Aggregates\PaidLeaveAccountAggregate;
use App\Domain\PaidLeaveAccount\Commands\CancelPaidLeaveUsage;

/**
 * @implements CommandHandler<CancelPaidLeaveUsage>
 */
class CancelPaidLeaveUsageHandler implements CommandHandler
{
    public function handle(Command $command): mixed
    {
        assert($command instanceof CancelPaidLeaveUsage);

        PaidLeaveAccountAggregate::retrieve($command->userId)
            ->cancelUsage(
                usageId: $command->usageId,
                cancelledByUserId: $command->cancelledByUserId,
                reason: $command->reason,
            )
            ->persist();
        return null;
    }
}
