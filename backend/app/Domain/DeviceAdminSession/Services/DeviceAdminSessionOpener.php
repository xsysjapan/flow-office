<?php

namespace App\Domain\DeviceAdminSession\Services;

use App\Domain\DeviceAdminSession\Events\DeviceAdminSessionEnded;
use App\Domain\DeviceAdminSession\Events\DeviceAdminSessionStarted;
use App\Domain\EventSourcing\EventStore;
use App\Models\Device;
use App\Models\DeviceAdminSession;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * 端末を管理者モードにする(UC-D006)。NFCタップ経由・ブートストラップ経由の両方の
 * CommandHandlerから共通で使う。
 */
class DeviceAdminSessionOpener
{
    private const SESSION_MINUTES = 30;

    public function __construct(private readonly EventStore $eventStore) {}

    public function open(Device $device, User $adminUser, string $source, ?int $authenticationKeyId): DeviceAdminSession
    {
        $existing = DeviceAdminSession::activeForDevice($device->id);
        if ($existing !== null) {
            $existing->ended_at = Carbon::now();
            $existing->save();

            $this->eventStore->append(
                aggregateType: 'device_admin_session',
                aggregateId: (string) $existing->id,
                event: new DeviceAdminSessionEnded(
                    deviceAdminSessionId: $existing->id,
                    deviceId: $device->id,
                ),
            );
        }

        $session = DeviceAdminSession::query()->create([
            'device_id' => $device->id,
            'admin_user_id' => $adminUser->id,
            'authentication_key_id' => $authenticationKeyId,
            'source' => $source,
            'started_at' => Carbon::now(),
            'expires_at' => Carbon::now()->addMinutes(self::SESSION_MINUTES),
        ]);

        $this->eventStore->append(
            aggregateType: 'device_admin_session',
            aggregateId: (string) $session->id,
            event: new DeviceAdminSessionStarted(
                deviceAdminSessionId: $session->id,
                deviceId: $device->id,
                adminUserId: $adminUser->id,
                source: $source,
            ),
        );

        return $session;
    }
}
