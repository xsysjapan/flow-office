<?php

namespace App\Domain\UserManagement\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\UserManagement\Aggregates\UserAggregate;
use App\Domain\UserManagement\Commands\SetPaidLeaveAutoGrantEnabled;
use App\Models\User;

/**
 * @implements CommandHandler<SetPaidLeaveAutoGrantEnabled>
 */
class SetPaidLeaveAutoGrantEnabledHandler implements CommandHandler
{
    public function handle(Command $command): User
    {
        assert($command instanceof SetPaidLeaveAutoGrantEnabled);

        $user = User::query()->findOrFail($command->userId);

        UserAggregate::retrieve($user->id)
            ->setPaidLeaveAutoGrantEnabled($command->enabled, $command->changedByUserId)
            ->persist();

        return $user->refresh();
    }
}
