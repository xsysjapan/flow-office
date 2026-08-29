<?php

namespace App\Http\Controllers\Api;

use App\Domain\ExternalIntegration\Aggregates\ExternalIntegrationConnectionAuditAggregate;
use App\Http\Controllers\Controller;
use App\Models\ExternalIntegrationConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * 外部連携(freee)のOAuth2認可コードフロー。docs/33-usecases-attendance-external-api.md参照。
 *
 * `AuthController`のMicrosoft SSOフローと同じ2段階パターンを踏襲する。
 * 1. GET .../oauth/redirect-url でfreeeの認可URLを取得しブラウザを遷移させる
 * 2. freeeからのリダイレクト(GET .../oauth/callback、認証不要)をこのAPIが受け、
 *    codeをトークンに交換して`ExternalIntegrationConnection`へ保存し、
 *    フロントエンドの連携設定画面へリダイレクトする
 *
 * `state`にはconnection idと有効期限を`Crypt`で暗号化して載せ、改ざん・再利用(期限切れ)を防ぐ
 * (`AuthController::linkRedirect()`のUC-004パターンを踏襲)。
 */
class ExternalIntegrationOAuthController extends Controller
{
    private const STATE_TTL_SECONDS = 600;

    public function redirectUrl(Request $request, string $externalIntegrationConnection): JsonResponse
    {
        $connection = ExternalIntegrationConnection::query()->findOrFail($externalIntegrationConnection);

        if ($connection->provider !== ExternalIntegrationConnection::PROVIDER_FREEE) {
            return response()->json(['message' => 'この連携先はOAuth2認可コードフローに対応していません。'], 422);
        }
        if ($connection->auth_type !== ExternalIntegrationConnection::AUTH_TYPE_OAUTH2) {
            return response()->json(['message' => 'この連携はOAuth2認証ではありません。'], 422);
        }
        if (blank($connection->client_id)) {
            return response()->json(['message' => 'クライアントIDが未設定です。先に登録してください。'], 422);
        }

        $state = encrypt([
            'connection_id' => $connection->id,
            'expires_at' => now()->addSeconds(self::STATE_TTL_SECONDS)->timestamp,
        ]);

        $params = http_build_query([
            'client_id' => $connection->client_id,
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'state' => $state,
        ]);

        $url = config('external_integrations.freee.authorize_endpoint').'?'.$params;

        return response()->json(['url' => $url]);
    }

    public function callback(Request $request): RedirectResponse
    {
        $frontendBase = rtrim(config('app.frontend_url'), '/').'/admin/external-integration-connections';

        $code = $request->query('code');
        $rawState = $request->query('state');

        try {
            if (! is_string($code) || ! is_string($rawState)) {
                throw new RuntimeException('codeまたはstateがありません。');
            }

            $state = decrypt($rawState);
            if (! is_array($state) || ! isset($state['connection_id'], $state['expires_at'])) {
                throw new RuntimeException('stateが不正です。');
            }
            if (now()->timestamp > (int) $state['expires_at']) {
                throw new RuntimeException('認可の有効期限が切れています。もう一度連携をやり直してください。');
            }

            $connection = ExternalIntegrationConnection::query()->findOrFail($state['connection_id']);

            $response = Http::asForm()->post(config('external_integrations.freee.token_endpoint'), [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->redirectUri(),
                'client_id' => $connection->client_id,
                'client_secret' => $connection->client_secret,
            ]);

            if ($response->failed()) {
                throw new RuntimeException('freeeとのトークン交換に失敗しました: '.$response->body());
            }

            $body = $response->json();

            DB::transaction(function () use ($connection, $body, $request): void {
                $before = $this->auditPayload($connection);

                $connection->access_token = $body['access_token'] ?? null;
                $connection->refresh_token = $body['refresh_token'] ?? $connection->refresh_token;
                $connection->token_expires_at = isset($body['expires_in'])
                    ? now()->addSeconds((int) $body['expires_in'])
                    : null;
                $connection->connected_by_user_id = $request->user()?->id ?? $connection->connected_by_user_id;
                $connection->connected_at = now();
                $connection->save();

                $after = $this->auditPayload($connection);
                ExternalIntegrationConnectionAuditAggregate::retrieve($connection->id)
                    ->recordUpdate($before, $after, $connection->connected_by_user_id ?? 'system')
                    ->persist();
            });

            return redirect()->away($frontendBase.'?oauth=success&provider=freee');
        } catch (RuntimeException $e) {
            return redirect()->away($frontendBase.'?oauth=error&message='.urlencode($e->getMessage()));
        }
    }

    /**
     * freeeのアプリ設定へ登録するリダイレクトURI。`Ms365ConfigResolver::applyToSocialiteConfig()`
     * と同じ組み立て方(`app.url` + `app.api_prefix`)をする。
     */
    private function redirectUri(): string
    {
        $base = rtrim(config('app.url'), '/');
        $base = rtrim("$base/".config('app.api_prefix', ''), '/');

        return "{$base}/admin/external-integration-connections/oauth/callback";
    }

    private function auditPayload(ExternalIntegrationConnection $connection): array
    {
        $payload = $connection->only(['id', 'provider', 'name', 'auth_type', 'status', 'enabled', 'external_office_id', 'custom_settings']);
        foreach (['access_token', 'refresh_token', 'api_key', 'client_id', 'client_secret'] as $secret) {
            $payload[$secret] = filled($connection->{$secret}) ? '[SET]' : null;
        }

        return $payload;
    }
}
