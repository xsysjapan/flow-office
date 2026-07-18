<?php

namespace App\Domain\Integration\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

class ApplicationIntegrationRegistered implements DomainEvent
{
    /**
     * @param  array<int, string>  $scopes
     */
    public function __construct(
        public readonly int $integrationId,
        public readonly int $ownerUserId,
        public readonly string $clientType,
        public readonly string $clientName,
        public readonly array $scopes,
        public readonly int $registeredByUserId,
    ) {}

    public function eventType(): string
    {
        return 'application_integration.registered';
    }

    public function payload(): array
    {
        return [
            'integration_id' => $this->integrationId,
            'owner_user_id' => $this->ownerUserId,
            'client_type' => $this->clientType,
            'client_name' => $this->clientName,
            'scopes' => $this->scopes,
            'registered_by_user_id' => $this->registeredByUserId,
        ];
    }
}
