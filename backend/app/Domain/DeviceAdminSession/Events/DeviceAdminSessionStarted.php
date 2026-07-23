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
        public readonly int $deviceId,
        public readonly int $adminUserId,
        public readonly ?int $authenticationKeyId,
        public readonly string $source,
        public readonly string $startedAt,
        public readonly string $expiresAt,
    ) {}
}
