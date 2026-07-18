<?php

namespace App\Domain\AuthenticationKey\Services;

/**
 * 認証キーの生値をそのまま保存しないためのハッシュ化サービス(docs/24-usecases-authentication-keys.md)。
 * normalize(input) → HMAC-SHA256(app_secret, normalized_key) で照合用のハッシュ値を得る。
 * app_secretにはLaravelのAPP_KEYを流用し、新しい鍵管理の仕組みを追加しない。
 */
class AuthenticationKeyHasher
{
    public function normalize(string $rawValue): string
    {
        return mb_strtoupper(trim($rawValue));
    }

    public function hash(string $rawValue): string
    {
        return hash_hmac('sha256', $this->normalize($rawValue), config('app.key'));
    }
}
