<?php

namespace App\Domain\Integration\Aggregates;

use App\Domain\Integration\Events\ApplicationIntegrationRegistered;
use App\Domain\Integration\Events\ApplicationIntegrationRevoked;
use App\Domain\Integration\Events\ApplicationIntegrationTokenReissued;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * application_integration集約。主キーは連番int(attendance_punches.integration_idが参照する
 * ため)のままなので、集約の識別はuuid(このAggregateRootのuuid = application_integrations.
 * aggregate_uuid)で行う(docs/29-event-sourcing-framework-migration.md参照)。
 */
class ApplicationIntegrationAggregate extends AggregateRoot
{
    private string $status = 'active';

    /** @var array<int, string> */
    private array $scopes = [];

    public function status(): string
    {
        return $this->status;
    }

    /**
     * @return array<int, string>
     */
    public function scopes(): array
    {
        return $this->scopes;
    }

    /**
     * @param  array<int, string>  $scopes
     */
    public function register(
        string $ownerType,
        int $ownerUserId,
        string $clientType,
        string $clientName,
        ?string $purpose,
        int $personalAccessTokenId,
        array $scopes,
        int $registeredByUserId,
    ): self {
        $this->recordThat(new ApplicationIntegrationRegistered(
            ownerType: $ownerType,
            ownerUserId: $ownerUserId,
            clientType: $clientType,
            clientName: $clientName,
            purpose: $purpose,
            personalAccessTokenId: $personalAccessTokenId,
            scopes: $scopes,
            registeredByUserId: $registeredByUserId,
        ));

        return $this;
    }

    public function reissueToken(int $personalAccessTokenId, int $reissuedByUserId): self
    {
        $this->recordThat(new ApplicationIntegrationTokenReissued(
            personalAccessTokenId: $personalAccessTokenId,
            reissuedByUserId: $reissuedByUserId,
        ));

        return $this;
    }

    public function revoke(int $revokedByUserId): self
    {
        $this->recordThat(new ApplicationIntegrationRevoked(
            revokedByUserId: $revokedByUserId,
        ));

        return $this;
    }

    protected function applyApplicationIntegrationRegistered(ApplicationIntegrationRegistered $event): void
    {
        $this->status = 'active';
        $this->scopes = $event->scopes;
    }

    protected function applyApplicationIntegrationRevoked(ApplicationIntegrationRevoked $event): void
    {
        $this->status = 'revoked';
    }
}
