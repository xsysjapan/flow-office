<?php

namespace App\Domain\Export\Services\Publishers;

use App\Domain\Export\Contracts\AuthStrategy;
use App\Domain\Export\Contracts\ExternalPublisher;
use App\Domain\Export\Contracts\PublishedArtifact;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * マネーフォワードクラウド経費APIへの送信(2段階呼び出し)。
 *
 * MoneyForwardExpenseApiPayloadBuilderが組み立てた`ex_transactions`配列を1件ずつ処理する。
 * 領収書(`receipt`)情報が付いている経費明細は、まず`upload_receipt`エンドポイントへ
 * 画像を送って`receipt_input`を取得し、それを`ex_transaction`作成リクエストへ紐付けてから
 * `ex_transactions`エンドポイントへPOSTする(docs/notes/moneyforward-api-investigation.md)。
 *
 * `upload_receipt`のリクエスト/レスポンス(`ReceiptInput`)の詳細フィールド名は開発者ポータルの
 * Swagger定義までは調査時に取得できておらず、ここではファイル名・Base64本文・MIMEタイプという
 * 一般的な形で送る。**本番投入前に、実際のReceiptInputスキーマ(expense.moneyforward.com/api/index.json
 * のSwagger定義)を確認してフィールド名を合わせること。**
 *
 * 実際のHTTP通信はIlluminate\Support\Facades\Httpを使うため、テストではHttp::fake()で
 * 差し替えられる(本フェーズでは実サーバーへの疎通確認は行わない)。
 */
class MoneyForwardExpensePublisher implements ExternalPublisher
{
    public function __construct(
        private readonly string $providerKey,
        private readonly AuthStrategy $authStrategy,
        private readonly string $officeId,
        private readonly string $exTransactionsEndpointTemplate,
        private readonly string $uploadReceiptEndpointTemplate,
    ) {}

    public function key(): string
    {
        return $this->providerKey;
    }

    public function publish(string $content, string $filename, array $context = []): PublishedArtifact
    {
        $payload = json_decode($content, true);
        if (! is_array($payload) || ! isset($payload['office_member_id'], $payload['ex_transactions'])) {
            throw new RuntimeException("{$this->providerKey}向けペイロードのJSONデコードに失敗しました。");
        }

        $officeMemberId = (string) $payload['office_member_id'];
        $exTransactionsEndpoint = $this->resolveEndpoint($this->exTransactionsEndpointTemplate, $officeMemberId);
        $uploadReceiptEndpoint = $this->resolveEndpoint($this->uploadReceiptEndpointTemplate, $officeMemberId);

        foreach ($payload['ex_transactions'] as $exTransaction) {
            $receiptInput = null;
            if (! empty($exTransaction['receipt'])) {
                $receiptInput = $this->uploadReceipt($uploadReceiptEndpoint, $exTransaction['receipt']);
            }

            $body = $exTransaction;
            unset($body['receipt'], $body['source_item_id']);
            if ($receiptInput !== null) {
                $body['receipt_input'] = $receiptInput;
            }

            $response = Http::withHeaders($this->authStrategy->authorizationHeaders())
                ->post($exTransactionsEndpoint, $body);

            if ($response->failed()) {
                throw new RuntimeException(
                    "{$this->providerKey}への経費明細(ex_transaction)送信に失敗しました(HTTP {$response->status()}): ".$response->body()
                );
            }
        }

        return new PublishedArtifact($content, $filename, null);
    }

    /**
     * @param  array{attachment_id: string, file_name: string, stored_path: string, mime_type: ?string}  $receipt
     * @return array<string, mixed>
     */
    private function uploadReceipt(string $endpoint, array $receipt): array
    {
        $disk = Storage::disk('local');
        $binary = $disk->exists($receipt['stored_path']) ? $disk->get($receipt['stored_path']) : null;

        $response = Http::withHeaders($this->authStrategy->authorizationHeaders())
            ->post($endpoint, [
                'file_name' => $receipt['file_name'],
                'content_type' => $receipt['mime_type'],
                'file_base64' => $binary !== null ? base64_encode($binary) : null,
            ]);

        if ($response->failed()) {
            throw new RuntimeException(
                "{$this->providerKey}への領収書アップロード(upload_receipt)に失敗しました(HTTP {$response->status()}): ".$response->body()
            );
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    private function resolveEndpoint(string $template, string $officeMemberId): string
    {
        return str_replace(
            ['{office_id}', '{office_member_id}'],
            [$this->officeId, $officeMemberId],
            $template,
        );
    }
}
