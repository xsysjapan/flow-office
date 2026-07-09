<?php

namespace App\Domain\User\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

/**
 * user.roles_changed (docs/17-events.md に追記済み)
 */
class UserRolesChanged implements DomainEvent
{
    /**
     * @param  array<int, string>  $previousRoleCodes
     * @param  array<int, string>  $newRoleCodes
     */
    public function __construct(
        public readonly int $userId,
        public readonly array $previousRoleCodes,
        public readonly array $newRoleCodes,
        public readonly int $changedByUserId,
    ) {}

    public function eventType(): string
    {
        return 'user.roles_changed';
    }

    public function payload(): array
    {
        return [
            'user_id' => $this->userId,
            'previous_role_codes' => $this->previousRoleCodes,
            'new_role_codes' => $this->newRoleCodes,
            'changed_by_user_id' => $this->changedByUserId,
        ];
    }
}
