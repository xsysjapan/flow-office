<?php

namespace App\Mcp\Support;

use Illuminate\Support\Facades\Http;

/**
 * backend/ のLaravel APIへの薄いHTTPクライアント(mcp-server/src/apiClient.tsのPHP版)。
 *
 * docs/25-usecases-integrations-mcp.md「MCPサーバーの責務」: mcp/は勤怠計算ロジックを
 * 一切持たず、backendの個人連携Sanctumトークン(UC-I001)を使って既存のbackend APIを
 * 呼び出すだけのクライアントである。backendのDBには一切アクセスしない。
 *
 * FlowOfficeApiClient(TS版)と異なり、複数の人間ユーザーを同時に捌く必要があるため、
 * トークンはコンストラクタで1回だけ受け取り、呼び出し単位のインスタンスとして使う
 * (EnsureMcpAccessTokenミドルウェアがリクエストごとに解決したトークンを注入する)。
 */
class BackendApiClient
{
    public function __construct(private readonly string $token)
    {
    }

    public function get(string $path, array $query = []): mixed
    {
        return $this->request('get', $path, ['query' => array_filter($query, fn ($v) => $v !== null)]);
    }

    public function post(string $path, array $body = [], array $headers = []): mixed
    {
        return $this->request('post', $path, ['json' => $body, 'headers' => $headers]);
    }

    public function put(string $path, array $body = [], array $headers = []): mixed
    {
        return $this->request('put', $path, ['json' => $body, 'headers' => $headers]);
    }

    public function delete(string $path, array $body = []): mixed
    {
        return $this->request('delete', $path, ['json' => $body]);
    }

    private function request(string $method, string $path, array $options): mixed
    {
        $baseUrl = rtrim(config('mcp.backend_api_base_url'), '/').'/';
        $url = $baseUrl.ltrim($path, '/');

        $request = Http::baseUrl($baseUrl)
            ->withToken($this->token)
            ->acceptJson()
            ->withHeaders($options['headers'] ?? []);

        $response = match ($method) {
            'get' => $request->get($url, $options['query'] ?? []),
            'post' => $request->post($url, $options['json'] ?? []),
            'put' => $request->put($url, $options['json'] ?? []),
            'delete' => $request->send('DELETE', $url, ['json' => $options['json'] ?? []]),
        };

        if ($response->failed()) {
            $body = $response->json();
            $message = is_array($body) && isset($body['message'])
                ? (string) $body['message']
                : sprintf('flow-office API がエラーを返しました(HTTP %d)', $response->status());

            throw new BackendApiException($message, $response->status(), $body);
        }

        return $response->json();
    }
}
