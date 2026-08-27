<?php

namespace App\Domain\Export\Contracts;

/**
 * 外部API連携(freee/moneyforward)の認可方式を統一的に扱う抽象。OAuth2Strategy(freee)は
 * トークンリフレッシュを内部で行い、ApiKeyStrategy(moneyforward)はAPIキーをそのまま使う。
 * 実際のHTTP通信はIlluminate\Support\Facades\Httpを使う実装に閉じ込め、テストでは
 * Http::fake()で差し替える(docs/33-usecases-attendance-external-api.md)。
 */
interface AuthStrategy
{
    /** 連携先キー('freee' / 'moneyforward')。 */
    public function provider(): string;

    /**
     * 必要であればトークンをリフレッシュした上で、外部APIリクエストに付与するヘッダーを返す。
     *
     * @return array<string, string>
     */
    public function authorizationHeaders(): array;
}
