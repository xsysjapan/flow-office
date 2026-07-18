<?php

namespace App\Domain\AuthenticationKey\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-K001/UC-K002: 認証キーを登録する(本人または管理者代理)。
 */
class IssueAuthenticationKey implements Command
{
    public function __construct(
        public readonly int $userId,
        public readonly string $keyType,
        public readonly string $displayName,
        public readonly string $rawKeyValue,
        public readonly ?string $validFrom,
        public readonly ?string $validUntil,
        public readonly ?array $metadata,
        public readonly int $registeredByUserId,
    ) {}
}
