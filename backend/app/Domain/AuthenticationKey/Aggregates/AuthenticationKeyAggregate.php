<?php

namespace App\Domain\AuthenticationKey\Aggregates;

use App\Domain\AuthenticationKey\Events\AuthenticationKeyDisabled;
use App\Domain\AuthenticationKey\Events\AuthenticationKeyIssued;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * authentication_key集約。主キーは連番int(device_admin_sessions.authentication_key_id等が
 * 参照するため)のままなので、集約の識別はuuid(このAggregateRootのuuid =
 * authentication_keys.aggregate_uuid)で行う(docs/29-event-sourcing-framework-migration.md参照)。
 */
class AuthenticationKeyAggregate extends AggregateRoot
{
    public function issue(
        int $userId,
        string $keyType,
        string $displayName,
        string $keyHash,
        ?string $validFrom,
        ?string $validUntil,
        ?array $metadata,
        int $registeredByUserId,
        string $registeredAt,
    ): self {
        $this->recordThat(new AuthenticationKeyIssued(
            userId: $userId,
            keyType: $keyType,
            displayName: $displayName,
            keyHash: $keyHash,
            validFrom: $validFrom,
            validUntil: $validUntil,
            metadata: $metadata,
            registeredByUserId: $registeredByUserId,
            registeredAt: $registeredAt,
        ));

        return $this;
    }

    public function disable(int $disabledByUserId, string $disabledAt): self
    {
        $this->recordThat(new AuthenticationKeyDisabled(
            disabledByUserId: $disabledByUserId,
            disabledAt: $disabledAt,
        ));

        return $this;
    }
}
