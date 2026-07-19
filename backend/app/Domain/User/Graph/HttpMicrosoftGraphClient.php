<?php

namespace App\Domain\User\Graph;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Microsoft Graph API (v1.0) を呼び出す実装。SSOログイン(UC-001)・Graphメール送信
 * (UC-N001)と共有する`system_settings`のEntra ID資格情報を使う。
 * クライアントクレデンシャルフロー(アプリ権限 User.Read.All)を前提にする。
 */
class HttpMicrosoftGraphClient implements MicrosoftGraphClient
{
    public function listUsers(): iterable
    {
        $token = $this->fetchAccessToken();
        $url = 'https://graph.microsoft.com/v1.0/users';
        $select = 'id,displayName,mail,department,jobTitle,accountEnabled';

        while ($url !== null) {
            $response = Http::withToken($token)
                ->get($url, $url === 'https://graph.microsoft.com/v1.0/users' ? ['$select' => $select] : [])
                ->throw();

            $body = $response->json();

            foreach ($body['value'] ?? [] as $raw) {
                yield new MicrosoftGraphUser(
                    entraUserId: $raw['id'],
                    displayName: $raw['displayName'] ?? '',
                    mail: $raw['mail'] ?? null,
                    department: $raw['department'] ?? null,
                    jobTitle: $raw['jobTitle'] ?? null,
                    accountEnabled: (bool) ($raw['accountEnabled'] ?? true),
                );
            }

            $url = $body['@odata.nextLink'] ?? null;
        }
    }

    private function fetchAccessToken(): string
    {
        $settings = SystemSetting::current();

        $response = Http::asForm()->post("https://login.microsoftonline.com/{$settings->m365_tenant_id}/oauth2/v2.0/token", [
            'client_id' => $settings->m365_client_id,
            'client_secret' => $settings->m365_client_secret,
            'scope' => 'https://graph.microsoft.com/.default',
            'grant_type' => 'client_credentials',
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Microsoft Graphのアクセストークン取得に失敗しました: '.$response->body());
        }

        return $response->json('access_token');
    }
}
