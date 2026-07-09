<?php

namespace App\Domain\User\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

/**
 * user.logged_in (UC-001: Microsoft SSOでログインする)
 */
class UserLoggedIn implements DomainEvent
{
    public function __construct(
        public readonly int $userId,
        public readonly bool $wasFirstLogin,
    ) {}

    public function eventType(): string
    {
        return 'user.logged_in';
    }

    public function payload(): array
    {
        return [
            'user_id' => $this->userId,
            'was_first_login' => $this->wasFirstLogin,
        ];
    }
}
