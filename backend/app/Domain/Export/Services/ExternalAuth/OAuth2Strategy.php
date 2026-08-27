<?php

namespace App\Domain\Export\Services\ExternalAuth;

use App\Domain\Export\Contracts\AuthStrategy;
use App\Models\ExternalIntegrationConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * freee人事労務向けのOAuth2.0認可戦略。アクセストークンの有効期限が切れている(または
 * 間もなく切れる)場合、リフレッシュトークンでトークンエンドポイントへ再取得を行い、
 * `external_integration_connections`へ暗号化して保存し直す。
 *
 * 実際のHTTP通信はHttp::fake()で差し替え可能なIlluminate\Support\Facades\Httpを使う
 * (本フェーズでは実サーバーへの疎通確認は行わない)。
 */
class OAuth2Strategy implements AuthStrategy
{
    public function __construct(
        private readonly ExternalIntegrationConnection $connection,
        private readonly string $tokenEndpoint,
    ) {}

    public function provider(): string
    {
        return $this->connection->provider;
    }

    public function authorizationHeaders(): array
    {
        if ($this->isExpiredOrExpiringSoon()) {
            $this->refresh();
        }

        if (blank($this->connection->access_token)) {
            throw new RuntimeException("{$this->connection->provider}の連携が未認可です。アクセストークンがありません。");
        }

        return ['Authorization' => 'Bearer '.$this->connection->access_token];
    }

    private function isExpiredOrExpiringSoon(): bool
    {
        if (blank($this->connection->access_token)) {
            return true;
        }

        $expiresAt = $this->connection->token_expires_at;

        return $expiresAt === null || Carbon::now()->addMinutes(1)->greaterThanOrEqualTo($expiresAt);
    }

    private function refresh(): void
    {
        if (blank($this->connection->refresh_token)) {
            throw new RuntimeException("{$this->connection->provider}のリフレッシュトークンが未設定のため、トークンを更新できません。再連携が必要です。");
        }

        $response = Http::asForm()->post($this->tokenEndpoint, [
            'grant_type' => 'refresh_token',
            'refresh_token' => $this->connection->refresh_token,
            'client_id' => $this->connection->client_id,
            'client_secret' => $this->connection->client_secret,
        ]);

        if ($response->failed()) {
            throw new RuntimeException("{$this->connection->provider}のトークンリフレッシュに失敗しました: ".$response->body());
        }

        $body = $response->json();

        $this->connection->access_token = $body['access_token'] ?? null;
        if (isset($body['refresh_token'])) {
            $this->connection->refresh_token = $body['refresh_token'];
        }
        $this->connection->token_expires_at = isset($body['expires_in'])
            ? Carbon::now()->addSeconds((int) $body['expires_in'])
            : null;
        $this->connection->save();
    }
}
