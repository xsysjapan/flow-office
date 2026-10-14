<?php

namespace App\Domain\PaidLeaveAccount\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\PaidLeaveAccount\Aggregates\PaidLeaveAccountAggregate;
use App\Domain\PaidLeaveAccount\Commands\AllocateUnallocatedPaidLeaveUsages;

/**
 * @implements CommandHandler<AllocateUnallocatedPaidLeaveUsages>
 */
class AllocateUnallocatedPaidLeaveUsagesHandler implements CommandHandler
{
    public function handle(Command $command): mixed
    {
        assert($command instanceof AllocateUnallocatedPaidLeaveUsages);

        // 充当できるものが無ければ集約は何も記録しない(冪等)。
        PaidLeaveAccountAggregate::retrieve($command->userId)
            ->allocateUnallocatedOnGrant($command->grantId)
            ->persist();

        return null;
    }
}
