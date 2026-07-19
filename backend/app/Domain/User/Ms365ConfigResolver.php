<?php

namespace App\Domain\User;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * SSOログイン(UC-001)・MS365ユーザー同期(UC-002)・Graphメール送信(UC-N001)で共有する
 * Entra ID資格情報を`system_settings`(DB)からLaravelのconfigへ流し込む。
 *
 * Socialiteの"azure"ドライバ(socialiteproviders/microsoft-azure)はconfig('services.azure.*')
 * を直接参照する実装のため、DBの値をリクエストのたびにconfig()へ反映させる必要がある
 * (docs/06-usecases-auth.md UC-001)。
 */
class Ms365ConfigResolver
{
    /**
     * DBの資格情報をSocialite "azure" ドライバが参照するconfigキーへ反映する。
     */
    public static function applyToSocialiteConfig(): void
    {
        $settings = SystemSetting::current();

        config([
            'services.azure.client_id' => $settings->m365_client_id,
            'services.azure.client_secret' => $settings->m365_client_secret,
            'services.azure.tenant' => $settings->m365_tenant_id ?: 'common',
            'services.azure.redirect' => $settings->m365_redirect_uri,
        ]);
    }

    /**
     * ローカル開発用モックOIDC(mock-oidc/)を使うかどうか。この値は開発専用エンドポイント
     * (MockOidcUserController・DevDatabaseResetController)のゲートも兼ねるため、マイグレーション前
     * (`system_settings`テーブル未作成時)やDB接続不可時は安全側(false=本物のEntra IDを使う)
     * にフォールバックする。
     */
    public static function mockEnabled(): bool
    {
        try {
            if (! Schema::hasTable('system_settings')) {
                return false;
            }

            return (bool) SystemSetting::current()->m365_mock_enabled;
        } catch (Throwable) {
            return false;
        }
    }
}
