<?php

namespace App\Mcp\OAuth\Repositories;

use App\Mcp\OAuth\Entities\AccessTokenEntity;
use App\Models\OauthAccessToken;
use App\Models\OauthClient;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;

class AccessTokenRepository implements AccessTokenRepositoryInterface
{
    public function getNewToken(ClientEntityInterface $clientEntity, array $scopes, ?string $userIdentifier = null): AccessTokenEntityInterface
    {
        $token = new AccessTokenEntity;
        $token->setClient($clientEntity);
        foreach ($scopes as $scope) {
            $token->addScope($scope);
        }
        if ($userIdentifier !== null) {
            $token->setUserIdentifier($userIdentifier);
        }

        return $token;
    }

    public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity): void
    {
        $client = OauthClient::query()->where('client_id', $accessTokenEntity->getClient()->getIdentifier())->firstOrFail();

        OauthAccessToken::query()->create([
            'id' => $accessTokenEntity->getIdentifier(),
            'oauth_client_id' => $client->id,
            'mcp_user_id' => $accessTokenEntity->getUserIdentifier(),
            'scopes' => array_map(fn ($scope) => $scope->getIdentifier(), $accessTokenEntity->getScopes()),
            'expires_at' => $accessTokenEntity->getExpiryDateTime(),
            'revoked' => false,
        ]);
    }

    public function revokeAccessToken(string $tokenId): void
    {
        OauthAccessToken::query()->where('id', $tokenId)->update(['revoked' => true]);
    }

    public function isAccessTokenRevoked(string $tokenId): bool
    {
        $token = OauthAccessToken::query()->find($tokenId);

        return $token === null || $token->revoked;
    }
}
