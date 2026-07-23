<?php

namespace App\Domain\DeviceAdminSession\Aggregates;

use App\Domain\DeviceAdminSession\Events\DeviceAdminSessionEnded;
use App\Domain\DeviceAdminSession\Events\DeviceAdminSessionStarted;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * device_admin_session集約。主キーがコマンド側生成のUUIDのため、行の新規作成自体も
 * DeviceAdminSessionProjectorに委ねられる(docs/29-event-sourcing-framework-migration.md参照)。
 */
class DeviceAdminSessionAggregate extends AggregateRoot
{
    public function start(
        string $deviceId,
        int $adminUserId,
        ?string $authenticationKeyId,
        string $source,
        string $startedAt,
        string $expiresAt,
    ): self {
        $this->recordThat(new DeviceAdminSessionStarted(
            deviceId: $deviceId,
            adminUserId: $adminUserId,
            authenticationKeyId: $authenticationKeyId,
            source: $source,
            startedAt: $startedAt,
            expiresAt: $expiresAt,
        ));

        return $this;
    }

    public function end(string $endedAt): self
    {
        $this->recordThat(new DeviceAdminSessionEnded(endedAt: $endedAt));

        return $this;
    }
}
