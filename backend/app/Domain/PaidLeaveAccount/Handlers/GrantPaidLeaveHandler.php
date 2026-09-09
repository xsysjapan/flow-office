<?php

namespace App\Domain\PaidLeaveAccount\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\PaidLeaveAccount\Aggregates\PaidLeaveAccountAggregate;
use App\Domain\PaidLeaveAccount\Commands\GrantPaidLeave;
use Illuminate\Support\Str;

/**
 * @implements CommandHandler<GrantPaidLeave>
 */
class GrantPaidLeaveHandler implements CommandHandler
{
    public function handle(Command $command): string
    {
        assert($command instanceof GrantPaidLeave);

        $grantId = (string) Str::uuid();

        PaidLeaveAccountAggregate::retrieve($command->userId)
            ->grant(
                grantId: $grantId,
                grantedOn: $command->grantedOn,
                expiresOn: $command->expiresOn,
                grantedDays: $command->grantedDays,
                grantReason: $command->grantReason,
                source: $command->source,
            )
            ->persist();

        return $grantId;
    }
}
