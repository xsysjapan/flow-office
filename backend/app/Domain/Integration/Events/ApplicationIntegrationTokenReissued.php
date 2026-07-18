<?php

namespace App\Domain\Integration\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

class ApplicationIntegrationTokenReissued implements DomainEvent
{
    public function __construct(
        public readonly int $integrationId,
        public readonly int $reissuedByUserId,
    ) {}

    public function eventType(): string
    {
        return 'application_integration.token_reissued';
    }

    public function payload(): array
    {
        return [
            'integration_id' => $this->integrationId,
            'reissued_by_user_id' => $this->reissuedByUserId,
        ];
    }
}
