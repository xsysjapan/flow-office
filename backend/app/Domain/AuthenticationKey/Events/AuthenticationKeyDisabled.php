<?php

namespace App\Domain\AuthenticationKey\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

class AuthenticationKeyDisabled implements DomainEvent
{
    public function __construct(
        public readonly int $authenticationKeyId,
        public readonly int $disabledByUserId,
    ) {}

    public function eventType(): string
    {
        return 'authentication_key.disabled';
    }

    public function payload(): array
    {
        return [
            'authentication_key_id' => $this->authenticationKeyId,
            'disabled_by_user_id' => $this->disabledByUserId,
        ];
    }
}
