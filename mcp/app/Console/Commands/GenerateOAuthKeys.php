<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * /mcp のアクセストークン(JWT)署名用RSA鍵ペアを生成する(league/oauth2-serverが要求する
 * CryptKey)。backend/のSanctum鍵とは無関係の、mcp/専用の鍵。
 */
#[Signature('mcp:oauth-keys {--force : 既存の鍵を上書きする}')]
#[Description('OAuth2アクセストークン署名用のRSA鍵ペアを生成する')]
class GenerateOAuthKeys extends Command
{
    public function handle(): int
    {
        $privatePath = base_path(config('mcp.oauth.private_key_path'));
        $publicPath = base_path(config('mcp.oauth.public_key_path'));

        if (! $this->option('force') && (file_exists($privatePath) || file_exists($publicPath))) {
            $this->error('既に鍵が存在します。上書きする場合は --force を付けてください。');

            return self::FAILURE;
        }

        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($resource === false) {
            $this->error('RSA鍵の生成に失敗しました。'.openssl_error_string());

            return self::FAILURE;
        }

        openssl_pkey_export($resource, $privateKey);
        $publicKey = openssl_pkey_get_details($resource)['key'];

        file_put_contents($privatePath, $privateKey);
        file_put_contents($publicPath, $publicKey);
        chmod($privatePath, 0600);
        // league/oauth2-serverのCryptKeyは公開鍵ファイルにも600/660を要求する。
        chmod($publicPath, 0600);

        $this->info("秘密鍵: {$privatePath}");
        $this->info("公開鍵: {$publicPath}");

        return self::SUCCESS;
    }
}
