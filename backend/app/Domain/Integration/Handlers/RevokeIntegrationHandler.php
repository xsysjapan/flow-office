<?php

namespace App\Domain\Integration\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Domain\Integration\Commands\RevokeIntegration;
use App\Domain\Integration\Events\ApplicationIntegrationRevoked;
use App\Models\ApplicationIntegration;

/**
 * @implements CommandHandler<RevokeIntegration>
 */
class RevokeIntegrationHandler implements CommandHandler
{
    public function __construct(private readonly EventStore $eventStore) {}

    public function handle(Command $command): ApplicationIntegration
    {
        assert($command instanceof RevokeIntegration);

        $integration = ApplicationIntegration::query()->findOrFail($command->integrationId);
        $integration->personalAccessToken?->delete();
        $integration->status = 'revoked';
        $integration->save();

        $this->eventStore->append(
            aggregateType: 'application_integration',
            aggregateId: (string) $integration->id,
            event: new ApplicationIntegrationRevoked(
                integrationId: $integration->id,
                revokedByUserId: $command->revokedByUserId,
            ),
        );

        return $integration;
    }
}
