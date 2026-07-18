<?php

namespace App\Domain\Integration\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

class ApplicationIntegrationRevoked implements DomainEvent
{
    public function __construct(
        public readonly int $integrationId,
        public readonly int $revokedByUserId,
    ) {}

    public function eventType(): string
    {
        return 'application_integration.revoked';
    }

    public function payload(): array
    {
        return [
            'integration_id' => $this->integrationId,
            'revoked_by_user_id' => $this->revokedByUserId,
        ];
    }
}
