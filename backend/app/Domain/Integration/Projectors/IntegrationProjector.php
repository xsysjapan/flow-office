<?php

namespace App\Domain\Integration\Projectors;

use App\Domain\Integration\Events\ApplicationIntegrationRegistered;
use App\Domain\Integration\Events\ApplicationIntegrationRevoked;
use App\Domain\Integration\Events\ApplicationIntegrationTokenReissued;
use App\Models\ApplicationIntegration;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * application_integration.* イベントから application_integrations / integration_scopes を
 * 作成・更新する。主キーがコマンド側生成のUUID(event->aggregateRootUuid())のため、行の
 * 新規作成自体もこのProjectorが担う(docs/29-event-sourcing-framework-migration.md参照)。
 */
class IntegrationProjector extends Projector
{
    public function onApplicationIntegrationRegistered(ApplicationIntegrationRegistered $event): void
    {
        $integration = ApplicationIntegration::query()->updateOrCreate(
            ['id' => $event->aggregateRootUuid()],
            [
                'owner_type' => $event->ownerType,
                'owner_user_id' => $event->ownerUserId,
                'client_type' => $event->clientType,
                'client_name' => $event->clientName,
                'purpose' => $event->purpose,
                'personal_access_token_id' => $event->personalAccessTokenId,
                'status' => 'active',
                'registered_by_user_id' => $event->registeredByUserId,
            ],
        );

        foreach ($event->scopes as $scope) {
            $integration->scopes()->create(['scope' => $scope]);
        }
    }

    public function onApplicationIntegrationTokenReissued(ApplicationIntegrationTokenReissued $event): void
    {
        ApplicationIntegration::query()
            ->whereKey($event->aggregateRootUuid())
            ->update(['personal_access_token_id' => $event->personalAccessTokenId]);
    }

    public function onApplicationIntegrationRevoked(ApplicationIntegrationRevoked $event): void
    {
        ApplicationIntegration::query()
            ->whereKey($event->aggregateRootUuid())
            ->update(['status' => 'revoked']);
    }
}
