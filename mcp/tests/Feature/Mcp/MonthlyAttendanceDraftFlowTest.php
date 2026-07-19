<?php

namespace Tests\Feature\Mcp;

use App\Models\OauthClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * docs/26-usecases-monthly-import.md: 下書き・インポートセッションのデータはmcp/自身のDBに
 * 保持し、backend/には既存の読み取り系API・日次編集API・月次提出API・ステートレスな
 * 検証エンドポイントのみを呼び出す(専用の下書きテーブル・エンドポイントをbackend/に持たせない)。
 */
class MonthlyAttendanceDraftFlowTest extends TestCase
{
    use RefreshDatabase;

    private const REDIRECT_URI = 'http://127.0.0.1:9999/callback';

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
            '*/auth/me' => Http::response(['id' => 42, 'email' => 'yuto.nagano@xsys.co.jp', 'timezone' => 'Asia/Tokyo'], 200),
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

    private function callTool(string $accessToken, string $name, array $arguments = []): array
    {
        $response = $this->withHeader('Authorization', "Bearer {$accessToken}")->postJson('/', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $arguments],
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        return json_decode($response->json('result.content.0.text'), true);
    }

    public function test_the_full_import_to_submission_flow_only_touches_backend_via_existing_apis(): void
    {
        $scopes = [
            'report:self:import', 'attendance:self:read', 'attendance:self:draft',
            'attendance:self:update', 'attendance:self:validate', 'attendance:self:submit',
        ];
        $accessToken = $this->obtainAccessToken($scopes);

        Http::fake([
            '*/auth/me' => Http::response(['id' => 42, 'email' => 'yuto.nagano@xsys.co.jp', 'timezone' => 'Asia/Tokyo'], 200),
            '*/attendance/import-preview' => Http::response([
                'items' => [
                    ['work_date' => '2026-07-01', 'existing' => null, 'differences' => []],
                ],
                'missing_dates' => [],
            ], 200),
            '*/attendance/months/2026-07' => Http::response(['days' => []], 200),
            '*/attendance/days' => Http::response(['id' => 501, 'work_date' => '2026-07-01'], 201),
            '*/attendance/months/2026-07/submit' => Http::response(['id' => 900, 'status' => 'submitted'], 200),
        ]);

        $session = $this->callTool($accessToken, 'create_attendance_import_session', ['target_month' => '2026-07']);
        $this->assertSame('created', $session['status']);

        $this->callTool($accessToken, 'upload_attendance_import_data', [
            'session_id' => $session['id'],
            'days' => [
                ['date' => '2026-07-01', 'startTime' => '09:00', 'endTime' => '18:00', 'breaks' => []],
            ],
        ]);

        $previewed = $this->callTool($accessToken, 'preview_attendance_import', ['session_id' => $session['id']]);
        $this->assertSame('previewed', $previewed['status']);
        $this->assertCount(1, $previewed['items']);

        $applied = $this->callTool($accessToken, 'apply_import_to_monthly_draft', ['session_id' => $session['id']]);
        $this->assertSame('applied', $applied['session']['status']);
        $this->assertSame('ready_to_submit', $applied['draft']['status']);
        $this->assertSame([['date' => '2026-07-01', 'status' => 'ACCEPTED', 'errors' => []]], $applied['results']);

        $submitted = $this->callTool($accessToken, 'submit_monthly_attendance', [
            'draft_id' => $applied['draft']['id'],
            'approver_user_id' => 7,
        ]);
        $this->assertSame('submitted', $submitted['draft']['status']);

        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_contains((string) $request->url(), '/attendance/days'));
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_contains((string) $request->url(), '/attendance/months/2026-07/submit'));
    }

    public function test_submit_is_rejected_locally_when_an_important_ai_inferred_field_is_unconfirmed(): void
    {
        $scopes = [
            'report:self:import', 'attendance:self:read', 'attendance:self:draft',
            'attendance:self:update', 'attendance:self:validate', 'attendance:self:submit',
        ];
        $accessToken = $this->obtainAccessToken($scopes);

        Http::fake([
            '*/auth/me' => Http::response(['id' => 42, 'email' => 'yuto.nagano@xsys.co.jp', 'timezone' => 'Asia/Tokyo'], 200),
            '*/attendance/import-preview' => Http::response([
                'items' => [
                    [
                        'work_date' => '2026-07-01',
                        'existing' => ['id' => 10, 'start_time' => '09:30', 'end_time' => '18:00', 'breaks' => [], 'locked' => false],
                        'differences' => [['code' => 'START_TIME_DIFF', 'severity' => 'warning', 'message' => '差異']],
                    ],
                ],
                'missing_dates' => [],
            ], 200),
            '*/attendance/months/2026-07' => Http::response(['days' => [['id' => 10, 'work_date' => '2026-07-01']]], 200),
            '*/attendance/days/10' => Http::response(['id' => 10, 'work_date' => '2026-07-01'], 200),
            '*/attendance/months/2026-07/submit' => Http::response(['id' => 900, 'status' => 'submitted'], 200),
        ]);

        $session = $this->callTool($accessToken, 'create_attendance_import_session', ['target_month' => '2026-07']);
        $this->callTool($accessToken, 'upload_attendance_import_data', [
            'session_id' => $session['id'],
            'days' => [
                ['date' => '2026-07-01', 'startTime' => '09:00', 'endTime' => '18:00', 'breaks' => []],
            ],
        ]);
        $this->callTool($accessToken, 'preview_attendance_import', ['session_id' => $session['id']]);
        $applied = $this->callTool($accessToken, 'apply_import_to_monthly_draft', ['session_id' => $session['id']]);

        $response = $this->withHeader('Authorization', "Bearer {$accessToken}")->postJson('/', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'submit_monthly_attendance',
                'arguments' => ['draft_id' => $applied['draft']['id'], 'approver_user_id' => 7],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('result.isError', true);
        $this->assertStringContainsString('AI_INFERRED_VALUE_UNCONFIRMED', $response->json('result.content.0.text'));
        Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), '/submit'));
    }
}
