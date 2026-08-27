<?php

namespace App\Domain\Export\Services\Publishers;

use App\Domain\Export\Contracts\AuthStrategy;
use App\Domain\Export\Contracts\ExternalPublisher;
use App\Domain\Export\Contracts\PublishedArtifact;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * freee/MoneyForward等の会計・労務クラウドAPIへ直接送信する(フェーズ2)。
 * `$content`はAttendanceApiPayloadBuilder/ExpenseApiPayloadBuilderが組み立てたペイロードの
 * JSON文字列を渡す。認可ヘッダーは$authStrategy(OAuth2Strategy/ApiKeyStrategy)が付与する。
 *
 * ペイロードのトップレベルに予約キー`_path`(連想配列)が含まれる場合、`{key}`形式の
 * プレースホルダーを含む`$endpoint`をその値で置換してからリクエストを送る(freee人事労務の
 * `PUT .../employees/{employee_id}/work_record_summaries/{year}/{month}`のようにIDを
 * パスパラメータで渡すAPI向け)。`_path`はリクエストボディには含めない。
 *
 * 実際のHTTP通信はIlluminate\Support\Facades\Httpを使うため、テストではHttp::fake()で
 * 差し替えられる(本フェーズでは実サーバーへの疎通確認は行わない)。
 */
class ExternalApiPublisher implements ExternalPublisher
{
    public function __construct(
        private readonly string $providerKey,
        private readonly AuthStrategy $authStrategy,
        private readonly string $endpoint,
        private readonly string $method = 'POST',
    ) {}

    public function key(): string
    {
        return $this->providerKey;
    }

    public function publish(string $content, string $filename, array $context = []): PublishedArtifact
    {
        $payload = json_decode($content, true);
        if (! is_array($payload)) {
            throw new RuntimeException("{$this->providerKey}向けペイロードのJSONデコードに失敗しました。");
        }

        $pathParams = $payload['_path'] ?? [];
        unset($payload['_path']);

        $endpoint = $this->resolveEndpoint($pathParams);

        $response = Http::withHeaders($this->authStrategy->authorizationHeaders())
            ->send(strtoupper($this->method), $endpoint, ['json' => $payload]);

        if ($response->failed()) {
            throw new RuntimeException(
                "{$this->providerKey}への送信に失敗しました(HTTP {$response->status()}): ".$response->body()
            );
        }

        return new PublishedArtifact($content, $filename, null);
    }

    /**
     * @param  array<string, mixed>  $pathParams
     */
    private function resolveEndpoint(array $pathParams): string
    {
        if ($pathParams === []) {
            return $this->endpoint;
        }

        $search = array_map(fn (string $key): string => '{'.$key.'}', array_keys($pathParams));
        $replace = array_map(strval(...), array_values($pathParams));

        return str_replace($search, $replace, $this->endpoint);
    }
}
