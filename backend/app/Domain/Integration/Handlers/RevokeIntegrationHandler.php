<?php

namespace App\Domain\Integration\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\Integration\Aggregates\ApplicationIntegrationAggregate;
use App\Domain\Integration\Commands\RevokeIntegration;
use App\Models\ApplicationIntegration;

/**
 * @implements CommandHandler<RevokeIntegration>
 */
class RevokeIntegrationHandler implements CommandHandler
{
    public function handle(Command $command): ApplicationIntegration
    {
        assert($command instanceof RevokeIntegration);

        $integration = ApplicationIntegration::query()->findOrFail($command->integrationId);
        $integration->personalAccessToken?->delete();

        ApplicationIntegrationAggregate::retrieve($integration->id)
            ->revoke($command->revokedByUserId)
            ->persist();

        return $integration->refresh();
    }
}
