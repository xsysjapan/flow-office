<?php

namespace App\Domain\Integration\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\Integration\Commands\RegisterIntegration;
use App\Domain\Integration\Events\ApplicationIntegrationRegistered;
use App\Models\ApplicationIntegration;
use App\Models\IntegrationClientType;
use App\Models\IntegrationOwnerType;
use App\Models\IntegrationScopeType;
use App\Models\User;

/**
 * UC-I001: 個人API・MCP連携を登録する。実際の認証キーはSanctumの個人アクセストークンを
 * そのまま流用し(`$user->createToken($clientName, $scopes)`)、新しいトークン発行の仕組みを
 * 増やさない。トークンのtokenableはユーザー本人のため、既存の`resolveTargetUserId`等の
 * 「本人またはadmin」ロジックはこのトークンでもそのまま機能する(docs/25参照)。
 *
 * @implements CommandHandler<RegisterIntegration>
 */
class RegisterIntegrationHandler implements CommandHandler
{
    public function __construct(private readonly EventStore $eventStore) {}

    /**
     * @return array{integration: ApplicationIntegration, plainTextToken: string}
     */
    public function handle(Command $command): array
    {
        assert($command instanceof RegisterIntegration);

        if (! in_array($command->clientType, IntegrationClientType::values(), true)) {
            throw new DomainRuleException('不正な連携種別です。');
        }

        $invalidScopes = array_diff($command->scopes, IntegrationScopeType::values());
        if ($invalidScopes !== []) {
            throw new DomainRuleException('許可されていないスコープが含まれています: '.implode(', ', $invalidScopes));
        }

        if ($command->scopes === []) {
            throw new DomainRuleException('少なくとも1つのスコープを選択してください。');
        }

        $user = User::query()->findOrFail($command->ownerUserId);
        $newToken = $user->createToken($command->clientName, $command->scopes);
        $plainTextToken = $newToken->plainTextToken;
        $tokenId = $newToken->accessToken->id;

        $integration = ApplicationIntegration::query()->create([
            'owner_type' => IntegrationOwnerType::PERSONAL,
            'owner_user_id' => $command->ownerUserId,
            'client_type' => $command->clientType,
            'client_name' => $command->clientName,
            'purpose' => $command->purpose,
            'personal_access_token_id' => $tokenId,
            'status' => 'active',
            'registered_by_user_id' => $command->registeredByUserId,
        ]);

        foreach ($command->scopes as $scope) {
            $integration->scopes()->create(['scope' => $scope]);
        }

        $this->eventStore->append(
            aggregateType: 'application_integration',
            aggregateId: (string) $integration->id,
            event: new ApplicationIntegrationRegistered(
                integrationId: $integration->id,
                ownerUserId: $command->ownerUserId,
                clientType: $command->clientType,
                clientName: $command->clientName,
                scopes: $command->scopes,
                registeredByUserId: $command->registeredByUserId,
            ),
        );

        return ['integration' => $integration->load('scopes'), 'plainTextToken' => $plainTextToken];
    }
}
