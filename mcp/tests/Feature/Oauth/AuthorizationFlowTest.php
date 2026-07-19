<?php

namespace Tests\Feature\Oauth;

use App\Models\OauthClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthorizationFlowTest extends TestCase
{
    use RefreshDatabase;

    private const REDIRECT_URI = 'http://127.0.0.1:9999/callback';

    private function pkce(): array
    {
        $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return [$verifier, $challenge];
    }

    private function registerClient(): OauthClient
    {
        return OauthClient::query()->create([
            'client_id' => 'mcp_'.Str::random(32),
            'client_name' => 'Test Claude',
            'redirect_uris' => [self::REDIRECT_URI],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_method' => 'none',
        ]);
    }

    private function linkBackendAccount(array $scopes): void
    {
        Http::fake([
            '*/auth/me' => Http::response(['id' => 1, 'email' => 'yuto.nagano@xsys.co.jp', 'name' => '永野ゆうと'], 200),
        ]);

        $response = $this->post('/link', [
            'token' => 'plain-backend-token',
            'scopes' => $scopes,
        ]);

        $response->assertRedirect();
    }

    public function test_full_authorization_code_flow_issues_a_working_access_token(): void
    {
        $client = $this->registerClient();
        [$verifier, $challenge] = $this->pkce();

        $this->linkBackendAccount(['profile:self:read', 'attendance:self:read', 'attendance:self:clock']);

        $authorizeQuery = [
            'response_type' => 'code',
            'client_id' => $client->client_id,
            'redirect_uri' => self::REDIRECT_URI,
            'scope' => 'profile:self:read attendance:self:read',
            'state' => 'xyz',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ];

        $showResponse = $this->get('/oauth/authorize?'.http_build_query($authorizeQuery));
        $showResponse->assertOk();
        $showResponse->assertSee('Test Claude', false);
        $showResponse->assertSee('自分の勤怠情報の閲覧', false);

        $approveResponse = $this->post('/oauth/authorize', [...$authorizeQuery, 'approve' => '1']);
        $approveResponse->assertStatus(302);

        $location = $approveResponse->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $redirectParams);
        $this->assertArrayHasKey('code', $redirectParams);
        $this->assertSame('xyz', $redirectParams['state']);

        $tokenResponse = $this->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $client->client_id,
            'redirect_uri' => self::REDIRECT_URI,
            'code' => $redirectParams['code'],
            'code_verifier' => $verifier,
        ]);

        $tokenResponse->assertOk();
        $tokenResponse->assertJsonStructure(['access_token', 'refresh_token', 'token_type', 'expires_in']);
        $this->assertSame('Bearer', $tokenResponse->json('token_type'));

        $accessToken = $tokenResponse->json('access_token');

        Http::fake([
            '*/auth/me' => Http::response(['id' => 1, 'email' => 'yuto.nagano@xsys.co.jp'], 200),
        ]);

        $mcpResponse = $this->withHeader('Authorization', "Bearer {$accessToken}")
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);

        $mcpResponse->assertOk();
        $toolNames = collect($mcpResponse->json('result.tools'))->pluck('name');
        $this->assertTrue($toolNames->contains('get_my_profile'));
        $this->assertGreaterThanOrEqual(30, $toolNames->count());
    }

    public function test_authorize_rejects_scopes_beyond_the_linked_backend_token(): void
    {
        $client = $this->registerClient();
        [, $challenge] = $this->pkce();

        $this->linkBackendAccount(['profile:self:read']);

        $authorizeQuery = [
            'response_type' => 'code',
            'client_id' => $client->client_id,
            'redirect_uri' => self::REDIRECT_URI,
            'scope' => 'attendance:self:submit',
            'state' => 'xyz',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ];

        $response = $this->get('/oauth/authorize?'.http_build_query($authorizeQuery));

        $response->assertStatus(403);
        $response->assertSee('要求された権限が不足しています', false);
    }

    public function test_authorize_redirects_to_link_when_no_backend_account_is_linked(): void
    {
        $client = $this->registerClient();
        [, $challenge] = $this->pkce();

        $authorizeQuery = [
            'response_type' => 'code',
            'client_id' => $client->client_id,
            'redirect_uri' => self::REDIRECT_URI,
            'scope' => 'profile:self:read',
            'state' => 'xyz',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ];

        $response = $this->get('/oauth/authorize?'.http_build_query($authorizeQuery));

        $response->assertStatus(302);
        $location = $response->headers->get('Location');
        $this->assertStringStartsWith(route('link.show'), $location);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $locationParams);
        parse_str((string) parse_url($locationParams['redirect'], PHP_URL_QUERY), $redirectedQuery);
        $this->assertEqualsCanonicalizing($authorizeQuery, $redirectedQuery);
    }
}
