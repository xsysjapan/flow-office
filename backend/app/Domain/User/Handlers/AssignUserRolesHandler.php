<?php

namespace App\Domain\User\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\User\Aggregates\UserAggregate;
use App\Domain\User\Commands\AssignUserRoles;
use App\Models\Role;
use App\Models\User;
use InvalidArgumentException;

/**
 * @implements CommandHandler<AssignUserRoles>
 */
class AssignUserRolesHandler implements CommandHandler
{
    public function handle(Command $command): User
    {
        assert($command instanceof AssignUserRoles);

        $user = User::query()->with('roles')->findOrFail($command->userId);
        $previousRoleCodes = $user->roles->pluck('code')->all();

        $roles = Role::query()->whereIn('code', $command->roleCodes)->get();
        if ($roles->count() !== count(array_unique($command->roleCodes))) {
            throw new InvalidArgumentException('存在しないロールコードが指定されました。');
        }

        UserAggregate::retrieve($user->id)
            ->changeRoles($previousRoleCodes, $roles->pluck('code')->all(), $command->changedByUserId)
            ->persist();

        return User::query()->with('roles')->findOrFail($user->id);
    }
}
