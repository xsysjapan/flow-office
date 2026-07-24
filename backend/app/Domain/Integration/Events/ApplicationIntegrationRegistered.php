<?php

namespace App\Domain\Integration\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * application_integration.registered。IntegrationProjectorが集約UUID(aggregateRootUuid())を
 * キーにapplication_integrationsの行を新規作成する(docs/29-event-sourcing-framework-migration.md)。
 */
class ApplicationIntegrationRegistered extends ShouldBeStored
{
    /**
     * @param  array<int, string>  $scopes
     */
    public function __construct(
        public readonly string $ownerType,
        public readonly string $ownerUserId,
        public readonly string $clientType,
        public readonly string $clientName,
        public readonly ?string $purpose,
        public readonly int $personalAccessTokenId,
        public readonly array $scopes,
        public readonly string $registeredByUserId,
    ) {}
}
