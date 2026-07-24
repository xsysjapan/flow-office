<?php

namespace App\Domain\Integration\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\Integration\Aggregates\ApplicationIntegrationAggregate;
use App\Domain\Integration\Commands\ReissueIntegrationToken;
use App\Models\ApplicationIntegration;

/**
 * UC-I003: アクセストークンを再発行する。既存トークンを失効させ、同じスコープ・
 * 連携名で新しいトークンを発行し直す(平文キーは発行時に一度だけ返す)。
 *
 * @implements CommandHandler<ReissueIntegrationToken>
 */
class ReissueIntegrationTokenHandler implements CommandHandler
{
    /**
     * @return array{integration: ApplicationIntegration, plainTextToken: string}
     */
    public function handle(Command $command): array
    {
        assert($command instanceof ReissueIntegrationToken);

        $integration = ApplicationIntegration::query()->with(['owner', 'scopes'])->findOrFail($command->integrationId);

        $aggregate = ApplicationIntegrationAggregate::retrieve($integration->id);

        if ($aggregate->status() !== 'active') {
            throw new DomainRuleException('無効化済みの連携はトークンを再発行できません。');
        }

        $integration->personalAccessToken?->delete();

        $newToken = $integration->owner->createToken($integration->client_name, $aggregate->scopes());

        $aggregate->reissueToken($newToken->accessToken->id, $command->reissuedByUserId)->persist();

        return [
            'integration' => $integration->refresh(),
            'plainTextToken' => $newToken->plainTextToken,
        ];
    }
}
