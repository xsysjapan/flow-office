<?php

namespace App\Domain\AuthenticationKey\Services;

use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\AuthenticationKey;

/**
 * docs/24-usecases-authentication-keys.md「打刻時の身元解決」。打刻元(端末)から受け取った
 * 認証キーの生値をハッシュ化し、有効な認証キーから対象社員を特定する。
 */
class AuthenticationKeyResolver
{
    public function __construct(private readonly AuthenticationKeyHasher $hasher) {}

    public function resolve(string $rawKeyValue, ?int $deviceId): AuthenticationKey
    {
        $keyHash = $this->hasher->hash($rawKeyValue);

        $key = AuthenticationKey::query()
            ->where('key_hash', $keyHash)
            ->with('deviceRules')
            ->first();

        if ($key === null) {
            throw new DomainRuleException('未登録の認証キーです。');
        }

        if (! $key->isUsableNow()) {
            throw new DomainRuleException('この認証キーは無効化されているか、有効期間外です。');
        }

        if (! $key->isUsableOnDevice($deviceId)) {
            throw new DomainRuleException('この端末ではこの認証キーを利用できません。');
        }

        return $key;
    }
}
