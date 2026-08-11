<?php

namespace App\Domain\UserManagement;

use SocialiteProviders\Azure\Provider as AzureProvider;

/**
 * ローカル開発用: Entra ID の代わりに mock-oidc/ のモックOIDCサーバーへリダイレクトする。
 * services.azure.mock_enabled が true のときのみ AppServiceProvider から利用される。
 *
 * 実サーバー(login.microsoftonline.com, graph.microsoft.com)へのホストがazureパッケージ側で
 * 固定されているため、authorize/token/graphの各エンドポイントをモックのURLへ差し替える。
 */
class LocalAzureProvider extends AzureProvider
{
    public function __construct($request, $clientId, $clientSecret, $redirectUrl, $guzzle = [])
    {
        parent::__construct($request, $clientId, $clientSecret, $redirectUrl, $guzzle);

        if (Ms365ConfigResolver::mockEnabled()) {
            $this->graphUrl = rtrim(config('services.azure.mock_internal_base_url'), '/').'/v1.0/me';
        }
    }

    protected function getAuthUrl($state)
    {
        if (! Ms365ConfigResolver::mockEnabled()) {
            return parent::getAuthUrl($state);
        }

        return $this->buildAuthUrlFromBase(
            rtrim(config('services.azure.mock_public_base_url'), '/').'/oauth2/v2.0/authorize',
            $state
        );
    }

    protected function getTokenUrl()
    {
        if (! Ms365ConfigResolver::mockEnabled()) {
            return parent::getTokenUrl();
        }

        return rtrim(config('services.azure.mock_internal_base_url'), '/').'/oauth2/v2.0/token';
    }
}
