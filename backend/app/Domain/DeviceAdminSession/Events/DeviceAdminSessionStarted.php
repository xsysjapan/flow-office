<?php

namespace App\Domain\DeviceAdminSession\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * device_admin_session.started。DeviceAdminSessionProjectorが集約UUID
 * (aggregateRootUuid() = device_admin_sessions.id)をキーに行を新規作成する。
 */
class DeviceAdminSessionStarted extends ShouldBeStored
{
    public function __construct(
        public readonly string $deviceId,
        public readonly string $adminUserId,
        public readonly ?string $authenticationKeyId,
        public readonly string $source,
        public readonly string $startedAt,
        public readonly string $expiresAt,
    ) {}
}
