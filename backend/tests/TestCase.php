<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * MS365(Entra ID)連携の設定は本来`system_settings`(初回オンボーディング)で管理者が
     * 設定するものだが、初回作成時のフォールバックとして`.env`(`services.azure.*`)を
     * 読む(`App\Models\SystemSetting::current()`参照)。開発者のローカル`.env`に
     * ローカル開発・E2Eテスト用のモック資格情報(mock-oidc向け)が設定されていても、
     * テストスイートはそれに影響されず常に「MS365未設定」から始まる必要があるため、
     * ここで明示的に上書きする(`phpunit.xml`の`<env>`だけでは、`php artisan test`経由の
     * 起動時にOS環境変数として既に`.env`の値が読み込まれてしまい上書きされないことがある)。
     */
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.azure.mock_enabled' => false,
            'services.azure.client_id' => null,
            'services.azure.client_secret' => null,
            'services.azure.tenant' => 'common',
        ]);
    }
}
