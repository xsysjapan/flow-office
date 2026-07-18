<?php

namespace App\Domain\Integration\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\Integration\Commands\ReissueIntegrationToken;
use App\Domain\Integration\Events\ApplicationIntegrationTokenReissued;
use App\Models\ApplicationIntegration;

/**
 * UC-I003: アクセストークンを再発行する。既存トークンを失効させ、同じスコープ・
 * 連携名で新しいトークンを発行し直す(平文キーは発行時に一度だけ返す)。
 *
 * @implements CommandHandler<ReissueIntegrationToken>
 */
class ReissueIntegrationTokenHandler implements CommandHandler
{
    public function __construct(private readonly EventStore $eventStore) {}

    /**
     * @return array{integration: ApplicationIntegration, plainTextToken: string}
     */
    public function handle(Command $command): array
    {
        assert($command instanceof ReissueIntegrationToken);

        $integration = ApplicationIntegration::query()->with(['owner', 'scopes'])->findOrFail($command->integrationId);

        if ($integration->status !== 'active') {
            throw new DomainRuleException('無効化済みの連携はトークンを再発行できません。');
        }

        $integration->personalAccessToken?->delete();

        $scopes = $integration->scopes->pluck('scope')->all();
        $newToken = $integration->owner->createToken($integration->client_name, $scopes);

        $integration->personal_access_token_id = $newToken->accessToken->id;
        $integration->save();

        $this->eventStore->append(
            aggregateType: 'application_integration',
            aggregateId: (string) $integration->id,
            event: new ApplicationIntegrationTokenReissued(
                integrationId: $integration->id,
                reissuedByUserId: $command->reissuedByUserId,
            ),
        );

        return ['integration' => $integration, 'plainTextToken' => $newToken->plainTextToken];
    }
}
