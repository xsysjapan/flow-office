<?php

namespace App\Domain\Integration\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\Integration\Aggregates\ApplicationIntegrationAggregate;
use App\Domain\Integration\Commands\RegisterIntegration;
use App\Models\ApplicationIntegration;
use App\Models\IntegrationClientType;
use App\Models\IntegrationOwnerType;
use App\Models\IntegrationScopeType;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * UC-I001: 個人API・MCP連携を登録する。実際の認証キーはSanctumの個人アクセストークンを
 * そのまま流用し(`$user->createToken($clientName, $scopes)`)、新しいトークン発行の仕組みを
 * 増やさない。トークンのtokenableはユーザー本人のため、既存の`resolveTargetUserId`等の
 * 「本人またはadmin」ロジックはこのトークンでもそのまま機能する(docs/25参照)。
 *
 * Sanctumトークンの発行はイベント再生では再現できない外部作用のため、集約に記録する前に
 * 先に実行し、その結果(トークンID)をイベントに積む(docs/29-event-sourcing-framework-migration.md)。
 *
 * @implements CommandHandler<RegisterIntegration>
 */
class RegisterIntegrationHandler implements CommandHandler
{
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

        // 「自分は誰か」を確認できる最低限のスコープは、選択したスコープに関わらず常に
        // 付与する(他の多くのツールが対象ユーザーIDの解決に必要とするため)。
        $scopes = array_values(array_unique([IntegrationScopeType::PROFILE_SELF_READ, ...$command->scopes]));

        $user = User::query()->findOrFail($command->ownerUserId);
        $newToken = $user->createToken($command->clientName, $scopes);
        $plainTextToken = $newToken->plainTextToken;

        $aggregateUuid = (string) Str::uuid();

        ApplicationIntegrationAggregate::retrieve($aggregateUuid)
            ->register(
                ownerType: IntegrationOwnerType::PERSONAL,
                ownerUserId: $command->ownerUserId,
                clientType: $command->clientType,
                clientName: $command->clientName,
                purpose: $command->purpose,
                personalAccessTokenId: $newToken->accessToken->id,
                scopes: $scopes,
                registeredByUserId: $command->registeredByUserId,
            )
            ->persist();

        $integration = ApplicationIntegration::query()->findOrFail($aggregateUuid);

        return ['integration' => $integration->load('scopes'), 'plainTextToken' => $plainTextToken];
    }
}
