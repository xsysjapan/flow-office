<?php

namespace Tests\Feature\Export;

use App\Models\ExternalIntegrationConnection;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Ramsey\Uuid\Uuid;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

/**
 * freeeのOAuth2認可コードフロー(ExternalIntegrationOAuthController)。
 * docs/33-usecases-attendance-external-api.md参照。
 */
class ExternalIntegrationOAuthTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create();
        $this->assignRole($admin, Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        return $admin;
    }

    private function createConnection(array $overrides = []): ExternalIntegrationConnection
    {
        return ExternalIntegrationConnection::create(array_merge([
            'id' => (string) Uuid::uuid4(),
            'provider' => ExternalIntegrationConnection::PROVIDER_FREEE,
            'name' => 'freee本社事業所',
            'auth_type' => ExternalIntegrationConnection::AUTH_TYPE_OAUTH2,
            'status' => ExternalIntegrationConnection::STATUS_ACTIVE,
            'enabled' => true,
            'client_id' => 'client-id-1234',
            'client_secret' => 'client-secret-5678',
        ], $overrides));
    }

    public function test_admin_can_get_the_oauth_authorize_url(): void
    {
        $admin = $this->admin();
        $connection = $this->createConnection();

        $response = $this->actingAs($admin)->getJson(
            "/api/admin/external-integration-connections/{$connection->id}/oauth/redirect-url",
        );

        $response->assertSuccessful();
        $url = $response->json('url');
        $this->assertStringStartsWith(config('external_integrations.freee.authorize_endpoint'), $url);
        $this->assertStringContainsString('client_id=client-id-1234', $url);
        $this->assertStringContainsString('response_type=code', $url);
        $this->assertStringContainsString('state=', $url);
    }

    public function test_redirect_url_is_rejected_when_client_id_is_missing(): void
    {
        $admin = $this->admin();
        $connection = $this->createConnection(['client_id' => null, 'client_secret' => null]);

        $response = $this->actingAs($admin)->getJson(
            "/api/admin/external-integration-connections/{$connection->id}/oauth/redirect-url",
        );

        $response->assertStatus(422);
    }

    public function test_callback_exchanges_code_for_tokens_with_a_valid_state(): void
    {
        Http::fake([
            'accounts.secure.freee.co.jp/public_api/token' => Http::response([
                'access_token' => 'new-access-token',
                'refresh_token' => 'new-refresh-token',
                'expires_in' => 3600,
            ]),
        ]);

        $connection = $this->createConnection();
        $state = encrypt(['connection_id' => $connection->id, 'expires_at' => now()->addMinutes(5)->timestamp]);

        $response = $this->get('/api/admin/external-integration-connections/oauth/callback?'.http_build_query([
            'code' => 'mock-auth-code',
            'state' => $state,
        ]));

        $response->assertRedirect();
        $this->assertStringContainsString('oauth=success', $response->headers->get('Location'));

        $connection->refresh();
        $this->assertSame('new-access-token', $connection->access_token);
        $this->assertSame('new-refresh-token', $connection->refresh_token);
        $this->assertNotNull($connection->token_expires_at);

        $this->assertTrue(
            EloquentStoredEvent::query()
                ->where('aggregate_uuid', $connection->id)
                ->where('event_class', 'external_integration_connection.updated')
                ->exists(),
        );

        Http::assertSent(fn ($request) => $request['grant_type'] === 'authorization_code'
            && $request['code'] === 'mock-auth-code'
            && $request['client_id'] === 'client-id-1234');
    }

    public function test_callback_rejects_an_invalid_state(): void
    {
        $response = $this->get('/api/admin/external-integration-connections/oauth/callback?'.http_build_query([
            'code' => 'mock-auth-code',
            'state' => 'not-a-valid-encrypted-state',
        ]));

        $response->assertRedirect();
        $this->assertStringContainsString('oauth=error', $response->headers->get('Location'));
    }

    public function test_callback_rejects_an_expired_state(): void
    {
        $connection = $this->createConnection();
        $state = encrypt(['connection_id' => $connection->id, 'expires_at' => now()->subMinute()->timestamp]);

        $response = $this->get('/api/admin/external-integration-connections/oauth/callback?'.http_build_query([
            'code' => 'mock-auth-code',
            'state' => $state,
        ]));

        $response->assertRedirect();
        $this->assertStringContainsString('oauth=error', $response->headers->get('Location'));

        $connection->refresh();
        $this->assertNull($connection->access_token);
    }
}
