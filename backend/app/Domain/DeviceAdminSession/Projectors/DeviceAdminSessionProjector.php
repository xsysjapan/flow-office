<?php

namespace App\Domain\DeviceAdminSession\Projectors;

use App\Domain\DeviceAdminSession\Events\DeviceAdminSessionEnded;
use App\Domain\DeviceAdminSession\Events\DeviceAdminSessionStarted;
use App\Models\DeviceAdminSession;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * device_admin_session.* イベントから device_admin_sessions を作成・更新する。
 */
class DeviceAdminSessionProjector extends Projector
{
    public function onDeviceAdminSessionStarted(DeviceAdminSessionStarted $event): void
    {
        DeviceAdminSession::query()->updateOrCreate(
            ['id' => $event->aggregateRootUuid()],
            [
                'device_id' => $event->deviceId,
                'admin_user_id' => $event->adminUserId,
                'authentication_key_id' => $event->authenticationKeyId,
                'source' => $event->source,
                'started_at' => $event->startedAt,
                'expires_at' => $event->expiresAt,
            ],
        );
    }

    public function onDeviceAdminSessionEnded(DeviceAdminSessionEnded $event): void
    {
        DeviceAdminSession::query()
            ->whereKey($event->aggregateRootUuid())
            ->update(['ended_at' => $event->endedAt]);
    }
}
