<?php

namespace App\Domain\UserManagement\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\UserManagement\Aggregates\UserAggregate;
use App\Domain\UserManagement\Commands\SetSpecialLeaveAutoGrantEnabled;
use App\Models\User;

/**
 * @implements CommandHandler<SetSpecialLeaveAutoGrantEnabled>
 */
class SetSpecialLeaveAutoGrantEnabledHandler implements CommandHandler
{
    public function handle(Command $command): User
    {
        assert($command instanceof SetSpecialLeaveAutoGrantEnabled);

        $user = User::query()->findOrFail($command->userId);

        UserAggregate::retrieve($user->id)
            ->setSpecialLeaveAutoGrantEnabled($command->enabled, $command->changedByUserId)
            ->persist();

        return $user->refresh();
    }
}
