<?php

namespace App\Domain\User\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * user.roles_changed (docs/17-events.md に追記済み)
 */
class UserRolesChanged extends ShouldBeStored
{
    /**
     * @param  array<int, string>  $previousRoleCodes
     * @param  array<int, string>  $newRoleCodes
     */
    public function __construct(
        public readonly array $previousRoleCodes,
        public readonly array $newRoleCodes,
        public readonly string $changedByUserId,
    ) {}
}
