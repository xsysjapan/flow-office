<?php

namespace Tests\Unit\Export;

use App\Domain\Export\Services\ExternalAuth\ApiKeyStrategy;
use App\Domain\Export\Services\ExternalAuth\OAuth2Strategy;
use App\Models\ExternalIntegrationConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * フェーズ2: AuthStrategy各実装(OAuth2Strategy/ApiKeyStrategy)の単体テスト。
 * 実サーバーへの疎通確認は行わず、Http::fake()で差し替える。
 * docs/33-usecases-attendance-external-api.md参照。
 */
class ExternalAuthStrategyTest extends TestCase
{
    use RefreshDatabase;

    public function test_oauth2_strategy_returns_bearer_header_without_refresh_when_token_is_valid(): void
    {
        Http::fake();

        $connection = ExternalIntegrationConnection::query()->create([
            'id' => (string) Str::uuid(),
            'provider' => ExternalIntegrationConnection::PROVIDER_FREEE,
            'auth_type' => ExternalIntegrationConnection::AUTH_TYPE_OAUTH2,
            'status' => ExternalIntegrationConnection::STATUS_ACTIVE,
            'access_token' => 'still-valid-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addHour(),
        ]);

        $strategy = new OAuth2Strategy($connection, 'https://accounts.secure.freee.co.jp/public_api/token');

        $this->assertSame(['Authorization' => 'Bearer still-valid-token'], $strategy->authorizationHeaders());
        Http::assertNothingSent();
    }

    public function test_oauth2_strategy_refreshes_expired_token_and_persists_it_encrypted(): void
    {
        Http::fake([
            'https://accounts.secure.freee.co.jp/public_api/token' => Http::response([
                'access_token' => 'brand-new-token',
                'refresh_token' => 'brand-new-refresh-token',
                'expires_in' => 3600,
            ], 200),
        ]);

        $connection = ExternalIntegrationConnection::query()->create([
            'id' => (string) Str::uuid(),
            'provider' => ExternalIntegrationConnection::PROVIDER_FREEE,
            'auth_type' => ExternalIntegrationConnection::AUTH_TYPE_OAUTH2,
            'status' => ExternalIntegrationConnection::STATUS_ACTIVE,
            'access_token' => 'expired-token',
            'refresh_token' => 'old-refresh-token',
            'token_expires_at' => now()->subMinute(),
        ]);

        $strategy = new OAuth2Strategy($connection, 'https://accounts.secure.freee.co.jp/public_api/token');

        $this->assertSame(['Authorization' => 'Bearer brand-new-token'], $strategy->authorizationHeaders());

        $connection->refresh();
        $this->assertSame('brand-new-token', $connection->access_token);
        $this->assertSame('brand-new-refresh-token', $connection->refresh_token);

        // encryptedキャストで暗号化されているため、DB上の生の値は平文と一致しない。
        $rawValue = \DB::table('external_integration_connections')->where('id', $connection->id)->value('access_token');
        $this->assertNotSame('brand-new-token', $rawValue);
    }

    public function test_api_key_strategy_returns_stored_key(): void
    {
        $connection = ExternalIntegrationConnection::query()->create([
            'id' => (string) Str::uuid(),
            'provider' => ExternalIntegrationConnection::PROVIDER_MONEYFORWARD,
            'auth_type' => ExternalIntegrationConnection::AUTH_TYPE_API_KEY,
            'status' => ExternalIntegrationConnection::STATUS_ACTIVE,
            'api_key' => 'mf-secret-key',
        ]);

        $strategy = new ApiKeyStrategy($connection);

        $this->assertSame(['X-Api-Key' => 'mf-secret-key'], $strategy->authorizationHeaders());
    }
}
