<?php

namespace App\Mcp\OAuth\Repositories;

use App\Mcp\OAuth\Entities\AuthCodeEntity;
use App\Models\OauthAuthCode;
use App\Models\OauthClient;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;

class AuthCodeRepository implements AuthCodeRepositoryInterface
{
    public function getNewAuthCode(): AuthCodeEntityInterface
    {
        return new AuthCodeEntity;
    }

    public function persistNewAuthCode(AuthCodeEntityInterface $authCodeEntity): void
    {
        $client = OauthClient::query()->where('client_id', $authCodeEntity->getClient()->getIdentifier())->firstOrFail();

        OauthAuthCode::query()->create([
            'id' => $authCodeEntity->getIdentifier(),
            'oauth_client_id' => $client->id,
            'mcp_user_id' => $authCodeEntity->getUserIdentifier(),
            'scopes' => array_map(fn ($scope) => $scope->getIdentifier(), $authCodeEntity->getScopes()),
            'redirect_uri' => $authCodeEntity->getRedirectUri(),
            // PKCE検証(code_challenge/method)はAuthCodeGrant側で別途保持されるため、
            // ここでは監査目的の記録として空文字を許容する。
            'code_challenge' => request()->input('code_challenge', ''),
            'code_challenge_method' => request()->input('code_challenge_method', 'S256'),
            'expires_at' => $authCodeEntity->getExpiryDateTime(),
            'revoked' => false,
        ]);
    }

    public function revokeAuthCode(string $codeId): void
    {
        OauthAuthCode::query()->where('id', $codeId)->update(['revoked' => true]);
    }

    public function isAuthCodeRevoked(string $codeId): bool
    {
        $code = OauthAuthCode::query()->find($codeId);

        return $code === null || $code->revoked;
    }
}
