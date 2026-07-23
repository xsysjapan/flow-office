<?php

namespace App\Domain\DeviceAdminSession\Services;

use App\Domain\DeviceAdminSession\Aggregates\DeviceAdminSessionAggregate;
use App\Models\Device;
use App\Models\DeviceAdminSession;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * 端末を管理者モードにする(UC-D006)。NFCタップ経由・ブートストラップ経由の両方の
 * CommandHandlerから共通で使う。既存のアクティブセッションがあれば、その終了と新規開始を
 * 同一トランザクションで記録する(複数集約にまたがるためAggregateRoot::persistInTransaction
 * を使う。docs/29-event-sourcing-framework-migration.md参照)。
 */
class DeviceAdminSessionOpener
{
    private const SESSION_MINUTES = 30;

    public function open(Device $device, User $adminUser, string $source, ?int $authenticationKeyId): DeviceAdminSession
    {
        $aggregatesToPersist = [];

        $existing = DeviceAdminSession::activeForDevice($device->id);
        if ($existing !== null) {
            $aggregatesToPersist[] = DeviceAdminSessionAggregate::retrieve($existing->id)
                ->end(Carbon::now()->format('Y-m-d H:i:s'));
        }

        $newSessionId = (string) Str::uuid();
        $aggregatesToPersist[] = DeviceAdminSessionAggregate::retrieve($newSessionId)->start(
            deviceId: $device->id,
            adminUserId: $adminUser->id,
            authenticationKeyId: $authenticationKeyId,
            source: $source,
            startedAt: Carbon::now()->format('Y-m-d H:i:s'),
            expiresAt: Carbon::now()->addMinutes(self::SESSION_MINUTES)->format('Y-m-d H:i:s'),
        );

        DeviceAdminSessionAggregate::persistInTransaction(...$aggregatesToPersist);

        return DeviceAdminSession::query()->findOrFail($newSessionId);
    }
}
