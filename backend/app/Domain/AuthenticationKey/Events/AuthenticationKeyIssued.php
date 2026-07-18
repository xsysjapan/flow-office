<?php

namespace App\Domain\AuthenticationKey\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

class AuthenticationKeyIssued implements DomainEvent
{
    public function __construct(
        public readonly int $authenticationKeyId,
        public readonly int $userId,
        public readonly string $keyType,
        public readonly string $displayName,
        public readonly int $registeredByUserId,
    ) {}

    public function eventType(): string
    {
        return 'authentication_key.issued';
    }

    public function payload(): array
    {
        return [
            'authentication_key_id' => $this->authenticationKeyId,
            'user_id' => $this->userId,
            'key_type' => $this->keyType,
            'display_name' => $this->displayName,
            'registered_by_user_id' => $this->registeredByUserId,
        ];
    }
}
