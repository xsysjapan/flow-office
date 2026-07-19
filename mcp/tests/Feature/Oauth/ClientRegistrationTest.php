<?php

namespace Tests\Feature\Oauth;

use App\Models\OauthClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registers_a_public_client_via_dcr(): void
    {
        $response = $this->postJson('/oauth/register', [
            'client_name' => 'Claude',
            'redirect_uris' => ['https://claude.ai/api/mcp/callback'],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('token_endpoint_auth_method', 'none');
        $response->assertJsonPath('client_name', 'Claude');
        $response->assertJsonStructure(['client_id', 'client_id_issued_at', 'redirect_uris', 'grant_types', 'response_types']);

        $this->assertDatabaseCount('oauth_clients', 1);
        $client = OauthClient::query()->first();
        $this->assertSame($response->json('client_id'), $client->client_id);
        $this->assertSame(['authorization_code', 'refresh_token'], $client->grant_types);
    }

    public function test_rejects_registration_without_redirect_uris(): void
    {
        $response = $this->postJson('/oauth/register', ['client_name' => 'Claude']);

        $response->assertStatus(422);
        $this->assertDatabaseCount('oauth_clients', 0);
    }

    public function test_rejects_unsupported_grant_types(): void
    {
        $response = $this->postJson('/oauth/register', [
            'client_name' => 'Claude',
            'redirect_uris' => ['https://claude.ai/callback'],
            'grant_types' => ['client_credentials'],
        ]);

        $response->assertStatus(422);
    }
}
