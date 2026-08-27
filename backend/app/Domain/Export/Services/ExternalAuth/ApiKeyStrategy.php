<?php

namespace App\Domain\Export\Services\ExternalAuth;

use App\Domain\Export\Contracts\AuthStrategy;
use App\Models\ExternalIntegrationConnection;
use RuntimeException;

/**
 * マネーフォワードクラウド給与向けのAPIキー認可戦略。トークンリフレッシュは行わず、
 * 保存済みのAPIキー(encryptedキャストで復号)をそのままヘッダーへ付与する。
 */
class ApiKeyStrategy implements AuthStrategy
{
    public function __construct(private readonly ExternalIntegrationConnection $connection) {}

    public function provider(): string
    {
        return $this->connection->provider;
    }

    public function authorizationHeaders(): array
    {
        if (blank($this->connection->api_key)) {
            throw new RuntimeException("{$this->connection->provider}のAPIキーが未設定です。連携設定を確認してください。");
        }

        return ['X-Api-Key' => $this->connection->api_key];
    }
}
