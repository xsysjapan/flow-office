<?php

namespace App\Domain\AuthenticationKey\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * authentication_key.issued。AuthenticationKeyProjectorが集約UUID(aggregateRootUuid())を
 * キーにauthentication_keysの行を新規作成する。
 */
class AuthenticationKeyIssued extends ShouldBeStored
{
    public function __construct(
        public readonly string $userId,
        public readonly string $keyType,
        public readonly string $displayName,
        public readonly string $keyHash,
        public readonly ?string $validFrom,
        public readonly ?string $validUntil,
        public readonly ?array $metadata,
        public readonly string $registeredByUserId,
        public readonly string $registeredAt,
    ) {}
}
