<?php

namespace App\Domain\User\Graph;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Microsoft Graph API (v1.0) を呼び出す実装。MS_GRAPH_* の環境変数が必要。
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
        $tenantId = config('services.microsoft_graph.tenant_id');

        $response = Http::asForm()->post("https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token", [
            'client_id' => config('services.microsoft_graph.client_id'),
            'client_secret' => config('services.microsoft_graph.client_secret'),
            'scope' => 'https://graph.microsoft.com/.default',
            'grant_type' => 'client_credentials',
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Microsoft Graphのアクセストークン取得に失敗しました: '.$response->body());
        }

        return $response->json('access_token');
    }
}
