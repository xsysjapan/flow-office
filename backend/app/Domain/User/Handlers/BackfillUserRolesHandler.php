<?php

namespace App\Domain\User\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\User\Aggregates\UserAggregate;
use App\Domain\User\Commands\BackfillUserRoles;
use App\Domain\User\Events\UserRolesMigratedFromLegacy;
use App\Models\User;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;

/**
 * @implements CommandHandler<BackfillUserRoles>
 */
class BackfillUserRolesHandler implements CommandHandler
{
    /**
     * @return int 補正イベントを記録したユーザー数
     */
    public function handle(Command $command): int
    {
        assert($command instanceof BackfillUserRoles);

        $alreadyMigratedUserIds = EloquentStoredEvent::query()
            ->where('event_class', 'user.roles_migrated_from_legacy')
            ->pluck('aggregate_uuid');

        $users = User::query()
            ->with('roles')
            ->whereHas('roles')
            ->whereNotIn('id', $alreadyMigratedUserIds)
            ->get();

        foreach ($users as $user) {
            UserAggregate::retrieve($user->id)
                ->migrateRolesFromLegacy($user->roles->pluck('code')->sort()->values()->all())
                ->persist();
        }

        return $users->count();
    }
}
