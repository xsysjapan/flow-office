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
        // 未使用の一時ペアリングトークン(PairDeviceHandlerが発行)も含めて、この端末の
        // Sanctumトークンをすべて削除する。失効後に古い一時トークンでペアリングし直され
        // 復活してしまうことを防ぐ。
        $device->tokens()->delete();
        $device->status = DeviceStatus::REVOKED;
        $device->revoked_at = now();
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
