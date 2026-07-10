<?php

namespace App\Domain\User\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Domain\User\Commands\AssignUserRoles;
use App\Domain\User\Events\UserRolesChanged;
use App\Models\Role;
use App\Models\User;
use InvalidArgumentException;

/**
 * @implements CommandHandler<AssignUserRoles>
 */
class AssignUserRolesHandler implements CommandHandler
{
    public function __construct(private readonly EventStore $eventStore) {}

    public function handle(Command $command): mixed
    {
        assert($command instanceof AssignUserRoles);

        $user = User::query()->with('roles')->findOrFail($command->userId);
        $previousRoleCodes = $user->roles->pluck('code')->all();

        $roles = Role::query()->whereIn('code', $command->roleCodes)->get();
        if ($roles->count() !== count(array_unique($command->roleCodes))) {
            throw new InvalidArgumentException('存在しないロールコードが指定されました。');
        }

        $user->roles()->sync($roles->pluck('id'));

        $this->eventStore->append(
            aggregateType: 'user',
            aggregateId: (string) $user->id,
            event: new UserRolesChanged(
                userId: $user->id,
                previousRoleCodes: $previousRoleCodes,
                newRoleCodes: $roles->pluck('code')->all(),
                changedByUserId: $command->changedByUserId,
            ),
        );
    }
}
