<?php

namespace App\Domain\UserManagement\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\UserManagement\Aggregates\UserAggregate;
use App\Domain\UserManagement\Commands\SetUserUsageStartDate;
use App\Models\User;

/**
 * @implements CommandHandler<SetUserUsageStartDate>
 */
class SetUserUsageStartDateHandler implements CommandHandler
{
    public function handle(Command $command): User
    {
        assert($command instanceof SetUserUsageStartDate);

        $user = User::query()->findOrFail($command->userId);

        UserAggregate::retrieve($user->id)->setUsageStartDate($command->usageStartDate, $command->changedByUserId)->persist();

        return $user->refresh();
    }
}
