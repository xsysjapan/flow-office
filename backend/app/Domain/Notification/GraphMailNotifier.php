<?php

namespace App\Domain\Notification;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Microsoft Graph API (`sendMail`) でHTMLメール通知を送る。Exchange OnlineはSMTP AUTH
 * (Basic認証)の廃止を進めているため、SMTPではなくGraph APIのアプリ専用トークン
 * (クライアントクレデンシャル)で送信する。
 *
 * `system_settings`でメール通知が有効化・設定されていない場合は送信自体を行わず、
 * ログ出力のみに留める(未設定環境でも通知処理自体は失敗させない。
 * 既存のTeams Webhook未設定時の扱いを踏襲)。
 */
class GraphMailNotifier implements Notifier
{
    private const TOKEN_CACHE_KEY_PREFIX = 'notification_mail.graph_access_token:';

    public function notify(User $recipient, string $title, string $summary, ?string $detailUrl): bool
    {
        $settings = SystemSetting::current();

        if (! $settings->notificationMailReady()) {
            Log::info('[メール通知(システム設定が未構成のためログのみ)] '.$title.' - '.$summary.($detailUrl ? " ({$detailUrl})" : ''));

            return true;
        }

        if (! $recipient->email) {
            Log::warning("メール通知の宛先アドレスが未設定です(user_id={$recipient->id})。");

            return false;
        }

        $accessToken = $this->resolveAccessToken($settings);
        if ($accessToken === null) {
            return false;
        }

        $html = view('emails.notification', [
            'title' => $title,
            'summary' => $summary,
            'detailUrl' => $detailUrl,
        ])->render();

        $response = Http::withToken($accessToken)
            ->post("https://graph.microsoft.com/v1.0/users/{$settings->notification_mail_sender_address}/sendMail", [
                'message' => [
                    'subject' => $title,
                    'body' => [
                        'contentType' => 'HTML',
                        'content' => $html,
                    ],
                    'toRecipients' => [
                        ['emailAddress' => ['address' => $recipient->email]],
                    ],
                ],
                'saveToSentItems' => false,
            ]);

        if ($response->failed()) {
            Log::warning('メール通知の送信に失敗しました: '.$response->status().' '.$response->body());

            return false;
        }

        Log::info("メール通知を送信しました: {$title} -> {$recipient->email}");

        return true;
    }

    /**
     * クライアントクレデンシャルフローでMicrosoft Graph用アクセストークンを取得する。
     * トークンは有効期限まで(安全マージン5分を引いて)キャッシュする。
     */
    private function resolveAccessToken(SystemSetting $settings): ?string
    {
        $cacheKey = self::TOKEN_CACHE_KEY_PREFIX.$settings->m365_tenant_id.':'.$settings->m365_client_id;

        $cached = Cache::get($cacheKey);
        if (is_string($cached)) {
            return $cached;
        }

        $response = Http::asForm()->post(
            "https://login.microsoftonline.com/{$settings->m365_tenant_id}/oauth2/v2.0/token",
            [
                'client_id' => $settings->m365_client_id,
                'client_secret' => $settings->m365_client_secret,
                'scope' => 'https://graph.microsoft.com/.default',
                'grant_type' => 'client_credentials',
            ],
        );

        if ($response->failed()) {
            Log::warning('メール通知用アクセストークンの取得に失敗しました: '.$response->status().' '.$response->body());

            return null;
        }

        $accessToken = $response->json('access_token');
        $expiresInSeconds = (int) $response->json('expires_in', 3600);

        Cache::put($cacheKey, $accessToken, max(60, $expiresInSeconds - 300));

        return $accessToken;
    }
}
