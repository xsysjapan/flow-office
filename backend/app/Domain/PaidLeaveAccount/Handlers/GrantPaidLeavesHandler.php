<?php

namespace App\Domain\PaidLeaveAccount\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\PaidLeaveAccount\Aggregates\PaidLeaveAccountAggregate;
use App\Domain\PaidLeaveAccount\Commands\GrantPaidLeaves;

/**
 * @implements CommandHandler<GrantPaidLeaves>
 */
class GrantPaidLeavesHandler implements CommandHandler
{
    /**
     * @return array<int, string> 付与ID(渡した順)
     */
    public function handle(Command $command): array
    {
        assert($command instanceof GrantPaidLeaves);

        PaidLeaveAccountAggregate::retrieve(PaidLeaveAccountAggregate::streamIdFor($command->userId))->forUser($command->userId)
            ->grantMany($command->grants)
            ->persist();

        return array_map(fn (array $grant) => $grant['grantId'], $command->grants);
    }
}
