<?php

namespace App\Mcp\OAuth\Repositories;

use App\Mcp\OAuth\Entities\RefreshTokenEntity;
use App\Models\OauthRefreshToken;
use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;

class RefreshTokenRepository implements RefreshTokenRepositoryInterface
{
    public function getNewRefreshToken(): ?RefreshTokenEntityInterface
    {
        return new RefreshTokenEntity;
    }

    public function persistNewRefreshToken(RefreshTokenEntityInterface $refreshTokenEntity): void
    {
        OauthRefreshToken::query()->create([
            'id' => $refreshTokenEntity->getIdentifier(),
            'oauth_access_token_id' => $refreshTokenEntity->getAccessToken()->getIdentifier(),
            'expires_at' => $refreshTokenEntity->getExpiryDateTime(),
            'revoked' => false,
        ]);
    }

    public function revokeRefreshToken(string $tokenId): void
    {
        OauthRefreshToken::query()->where('id', $tokenId)->update(['revoked' => true]);
    }

    public function isRefreshTokenRevoked(string $tokenId): bool
    {
        $token = OauthRefreshToken::query()->find($tokenId);

        return $token === null || $token->revoked;
    }
}
