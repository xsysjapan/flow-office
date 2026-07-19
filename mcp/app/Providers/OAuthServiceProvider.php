<?php

namespace App\Providers;

use App\Mcp\OAuth\Repositories\AccessTokenRepository;
use App\Mcp\OAuth\Repositories\AuthCodeRepository;
use App\Mcp\OAuth\Repositories\ClientRepository;
use App\Mcp\OAuth\Repositories\RefreshTokenRepository;
use App\Mcp\OAuth\Repositories\ScopeRepository;
use DateInterval;
use Illuminate\Support\ServiceProvider;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\Grant\RefreshTokenGrant;
use League\OAuth2\Server\ResourceServer;

/**
 * league/oauth2-server の配線。mcp/ はClaude等のMCPクライアントに対する独立した
 * OAuth2認可サーバー(DCR含む)であり、backend/とは別の鍵・別のトークン基盤を持つ
 * (backend/はSanctum個人アクセストークンのみで、認可サーバーは持たない)。
 */
class OAuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AuthorizationServer::class, function () {
            $server = new AuthorizationServer(
                new ClientRepository,
                new AccessTokenRepository,
                new ScopeRepository,
                base_path(config('mcp.oauth.private_key_path')),
                $this->encryptionKey(),
            );

            $authCodeGrant = new AuthCodeGrant(
                new AuthCodeRepository,
                new RefreshTokenRepository,
                new DateInterval(sprintf('PT%dM', config('mcp.oauth.auth_code_ttl_minutes'))),
            );
            $authCodeGrant->setRefreshTokenTTL(
                new DateInterval(sprintf('P%dD', config('mcp.oauth.refresh_token_ttl_days')))
            );

            $server->enableGrantType(
                $authCodeGrant,
                new DateInterval(sprintf('PT%dM', config('mcp.oauth.access_token_ttl_minutes'))),
            );

            $server->enableGrantType(
                new RefreshTokenGrant(new RefreshTokenRepository),
                new DateInterval(sprintf('PT%dM', config('mcp.oauth.access_token_ttl_minutes'))),
            );

            return $server;
        });

        $this->app->singleton(ResourceServer::class, function () {
            return new ResourceServer(
                new AccessTokenRepository,
                base_path(config('mcp.oauth.public_key_path')),
            );
        });
    }

    public function boot(): void
    {
        //
    }

    private function encryptionKey(): string
    {
        // AuthCodeGrant/RefreshTokenGrant はこの鍵(パスワードとして扱われ、内部でPBKDF2に
        // かけられる)で認可コード・リフレッシュトークンのペイロードを対称暗号化する
        // (backend/のSanctumトークンとは無関係の、mcp/専用の鍵)。APP_KEYを流用し、
        // 新しい秘密情報の管理先を増やさない。
        return (string) config('app.key');
    }
}
