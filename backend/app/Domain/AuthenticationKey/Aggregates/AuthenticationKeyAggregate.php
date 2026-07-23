<?php

namespace App\Domain\AuthenticationKey\Aggregates;

use App\Domain\AuthenticationKey\Events\AuthenticationKeyDisabled;
use App\Domain\AuthenticationKey\Events\AuthenticationKeyIssued;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * authentication_key集約。主キーがコマンド側生成のUUID(このAggregateRootのuuid =
 * authentication_keys.id)のため、行の新規作成自体もAuthenticationKeyProjectorに委ねられる
 * (docs/29-event-sourcing-framework-migration.md参照)。
 */
class AuthenticationKeyAggregate extends AggregateRoot
{
    public function issue(
        string $userId,
        string $keyType,
        string $displayName,
        string $keyHash,
        ?string $validFrom,
        ?string $validUntil,
        ?array $metadata,
        string $registeredByUserId,
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

    public function disable(string $disabledByUserId, string $disabledAt): self
    {
        $this->recordThat(new AuthenticationKeyDisabled(
            disabledByUserId: $disabledByUserId,
            disabledAt: $disabledAt,
        ));

        return $this;
    }
}
