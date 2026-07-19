<?php

namespace App\Mcp\OAuth\Repositories;

use App\Mcp\OAuth\Entities\ClientEntity;
use App\Mcp\OAuth\Entities\ScopeEntity;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;

/**
 * mcp/ のOAuthスコープは config('mcp.scopes') のキー(backend/ の integration_scopes と
 * 同じ文字列、docs/16-database-schema.md)に限定する。
 */
class ScopeRepository implements ScopeRepositoryInterface
{
    public function getScopeEntityByIdentifier(string $identifier): ?ScopeEntityInterface
    {
        if (! array_key_exists($identifier, config('mcp.scopes'))) {
            return null;
        }

        return new ScopeEntity($identifier);
    }

    /**
     * @param  ScopeEntityInterface[]  $scopes
     * @return ScopeEntityInterface[]
     */
    public function finalizeScopes(
        array $scopes,
        string $grantType,
        ClientEntityInterface $clientEntity,
        ?string $userIdentifier = null,
        ?string $authCodeId = null
    ): array {
        return $scopes;
    }
}
