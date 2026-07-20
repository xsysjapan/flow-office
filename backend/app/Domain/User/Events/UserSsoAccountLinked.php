<?php

namespace App\Domain\User\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

/**
 * user.sso_account_linked (UC-004: ローカルユーザーがMicrosoft 365アカウントと連携する)
 */
class UserSsoAccountLinked implements DomainEvent
{
    public function __construct(
        public readonly int $userId,
        public readonly string $entraUserId,
    ) {}

    public function eventType(): string
    {
        return 'user.sso_account_linked';
    }

    public function payload(): array
    {
        return [
            'user_id' => $this->userId,
            'entra_user_id' => $this->entraUserId,
        ];
    }
}
