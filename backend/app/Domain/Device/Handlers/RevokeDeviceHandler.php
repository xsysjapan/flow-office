<?php

namespace App\Domain\Device\Handlers;

use App\Domain\Device\Commands\RevokeDevice;
use App\Domain\Device\Events\DeviceRevoked;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Models\Device;
use App\Models\DeviceStatus;

/**
 * @implements CommandHandler<RevokeDevice>
 */
class RevokeDeviceHandler implements CommandHandler
{
    public function __construct(private readonly EventStore $eventStore) {}

    public function handle(Command $command): Device
    {
        assert($command instanceof RevokeDevice);

        $device = Device::query()->findOrFail($command->deviceId);
        $device->tokens()->delete();
        $device->status = DeviceStatus::REVOKED;
        $device->revoked_at = now();
        // 発行済みの未使用ペアリングコードが残っていると、失効後もそのコードで
        // ペアリングし直され復活してしまうため、あわせて無効化する。
        $device->pairing_code_hash = null;
        $device->pairing_code_expires_at = null;
        $device->save();

        $this->eventStore->append(
            aggregateType: 'device',
            aggregateId: (string) $device->id,
            event: new DeviceRevoked(
                deviceId: $device->id,
                revokedByUserId: $command->revokedByUserId,
                reason: $command->reason,
            ),
        );

        return $device;
    }
}
