<?php

namespace App\Domain\AuthenticationKey\Projectors;

use App\Domain\AuthenticationKey\Events\AuthenticationKeyDisabled;
use App\Domain\AuthenticationKey\Events\AuthenticationKeyIssued;
use App\Models\AuthenticationKey;
use App\Models\AuthenticationKeyStatus;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * authentication_key.* イベントから authentication_keys を作成・更新する。主キーは連番int
 * のままのため、集約UUID(event->aggregateRootUuid())をキーにupdateOrCreateする
 * (docs/29-event-sourcing-framework-migration.md参照)。
 */
class AuthenticationKeyProjector extends Projector
{
    public function onAuthenticationKeyIssued(AuthenticationKeyIssued $event): void
    {
        AuthenticationKey::query()->updateOrCreate(
            ['aggregate_uuid' => $event->aggregateRootUuid()],
            [
                'user_id' => $event->userId,
                'key_type' => $event->keyType,
                'display_name' => $event->displayName,
                'key_hash' => $event->keyHash,
                'status' => AuthenticationKeyStatus::ACTIVE,
                'valid_from' => $event->validFrom,
                'valid_until' => $event->validUntil,
                'metadata_json' => $event->metadata,
                'registered_by_user_id' => $event->registeredByUserId,
                'registered_at' => $event->registeredAt,
            ],
        );
    }

    public function onAuthenticationKeyDisabled(AuthenticationKeyDisabled $event): void
    {
        AuthenticationKey::query()
            ->where('aggregate_uuid', $event->aggregateRootUuid())
            ->update([
                'status' => AuthenticationKeyStatus::DISABLED,
                'disabled_at' => $event->disabledAt,
            ]);
    }
}
