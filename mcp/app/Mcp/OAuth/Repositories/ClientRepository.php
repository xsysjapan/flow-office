<?php

namespace App\Mcp\OAuth\Repositories;

use App\Mcp\OAuth\Entities\ClientEntity;
use App\Models\OauthClient;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;

/**
 * DCR(RFC 7591)で登録されたクライアント(oauth_clients)を参照する。
 * 本アプリは公開クライアント(client_secretなし、PKCE必須)しか発行しないため、
 * validateClientは「登録済みかつ許可されたgrant_typeか」だけを確認する。
 */
class ClientRepository implements ClientRepositoryInterface
{
    public function getClientEntity(string $clientIdentifier): ?ClientEntity
    {
        $client = OauthClient::query()->where('client_id', $clientIdentifier)->first();
        if ($client === null) {
            return null;
        }

        return new ClientEntity(
            $client->client_id,
            $client->client_name,
            $client->redirect_uris,
            isConfidential: false,
        );
    }

    public function validateClient(string $clientIdentifier, ?string $clientSecret, ?string $grantType): bool
    {
        $client = OauthClient::query()->where('client_id', $clientIdentifier)->first();
        if ($client === null) {
            return false;
        }

        if ($grantType !== null && ! in_array($grantType, $client->grant_types, true)) {
            return false;
        }

        // 公開クライアントのみサポートするため、client_secretは要求しない(PKCEで代替する)。
        return true;
    }
}
