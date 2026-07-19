<?php

namespace Tests\Feature\Mcp;

use App\Models\OauthClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class McpControllerTest extends TestCase
{
    use RefreshDatabase;

    private const REDIRECT_URI = 'http://127.0.0.1:9999/callback';

    /**
     * 実際のOAuth2フロー(link → authorize → token)を通してアクセストークンを取得する。
     * league/oauth2-serverのJWT構造を手作りで模倣しない(実装詳細に依存させないため)。
     */
    private function obtainAccessToken(array $scopes): string
    {
        $client = OauthClient::query()->create([
            'client_id' => 'mcp_'.Str::random(32),
            'client_name' => 'Test Claude',
            'redirect_uris' => [self::REDIRECT_URI],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_method' => 'none',
        ]);

        $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        Http::fake([
            '*/auth/me' => Http::response(['id' => 1, 'email' => 'yuto.nagano@xsys.co.jp', 'name' => '永野ゆうと'], 200),
        ]);

        $this->post('/link', [
            'token' => 'plain-backend-token',
            'scopes' => array_values(array_unique([...$scopes, 'profile:self:read'])),
        ])->assertRedirect();

        $authorizeQuery = [
            'response_type' => 'code',
            'client_id' => $client->client_id,
            'redirect_uri' => self::REDIRECT_URI,
            'scope' => implode(' ', $scopes),
            'state' => 'xyz',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ];

        $approveResponse = $this->post('/oauth/authorize', [...$authorizeQuery, 'approve' => '1']);
        parse_str((string) parse_url($approveResponse->headers->get('Location'), PHP_URL_QUERY), $redirectParams);

        $tokenResponse = $this->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $client->client_id,
            'redirect_uri' => self::REDIRECT_URI,
            'code' => $redirectParams['code'],
            'code_verifier' => $verifier,
        ]);

        return $tokenResponse->json('access_token');
    }

    public function test_tools_call_returns_success_shape_for_a_successful_backend_call(): void
    {
        $accessToken = $this->obtainAccessToken(['profile:self:read']);

        Http::fake([
            '*/auth/me' => Http::response(['id' => 1, 'email' => 'yuto.nagano@xsys.co.jp', 'name' => '永野ゆうと'], 200),
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$accessToken}")->postJson('/', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'get_my_profile', 'arguments' => []],
        ]);

        $response->assertOk();
        $response->assertJsonMissingPath('result.isError');
        $text = $response->json('result.content.0.text');
        $this->assertStringContainsString('yuto.nagano@xsys.co.jp', $text);
    }

    public function test_tools_call_maps_backend_error_to_is_error_shape(): void
    {
        $accessToken = $this->obtainAccessToken(['profile:self:read', 'attendance:self:clock']);

        Http::fake([
            '*/attendance/clock-in' => Http::response(['message' => '既に出勤済みです。'], 409),
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$accessToken}")->postJson('/', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'clock_in', 'arguments' => []],
        ]);

        $response->assertOk();
        $response->assertJsonPath('result.isError', true);
        $this->assertStringContainsString('既に出勤済みです', $response->json('result.content.0.text'));
    }

    public function test_tools_call_denies_a_tool_outside_the_granted_oauth_scopes(): void
    {
        $accessToken = $this->obtainAccessToken(['profile:self:read']);

        $response = $this->withHeader('Authorization', "Bearer {$accessToken}")->postJson('/', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'clock_in', 'arguments' => []],
        ]);

        $response->assertOk();
        $response->assertJsonPath('result.isError', true);
        $this->assertStringContainsString('attendance:self:clock', $response->json('result.content.0.text'));
    }

    public function test_tools_list_returns_all_registered_tools(): void
    {
        $accessToken = $this->obtainAccessToken(['profile:self:read']);

        $response = $this->withHeader('Authorization', "Bearer {$accessToken}")->postJson('/', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ]);

        $response->assertOk();
        $names = collect($response->json('result.tools'))->pluck('name');
        $this->assertContains('get_my_profile', $names);
        $this->assertContains('cancel_monthly_attendance_submission', $names);
        $this->assertCount(31, $names);
    }

    public function test_mcp_endpoint_rejects_requests_without_a_bearer_token(): void
    {
        $response = $this->postJson('/', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);

        $response->assertStatus(401);
    }
}
