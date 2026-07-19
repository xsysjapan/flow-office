<?php

namespace App\Domain\User\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

/**
 * user.onboarded_as_admin
 */
class UserOnboardedAsAdmin implements DomainEvent
{
    public function __construct(
        public readonly int $userId,
        public readonly string $name,
        public readonly ?string $email,
        /** 'sso' または 'local'。docs/06-usecases-auth.md UC-000参照。 */
        public readonly string $authMethod,
    ) {}

    public function eventType(): string
    {
        return 'user.onboarded_as_admin';
    }

    public function payload(): array
    {
        return [
            'user_id' => $this->userId,
            'name' => $this->name,
            'email' => $this->email,
            'auth_method' => $this->authMethod,
        ];
    }
}
