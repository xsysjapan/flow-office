<?php

namespace App\Domain\Device\Handlers;

use App\Domain\Device\Commands\DisableDevice;
use App\Domain\Device\Events\DeviceDisabled;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Models\Device;
use App\Models\DeviceStatus;

/**
 * @implements CommandHandler<DisableDevice>
 */
class DisableDeviceHandler implements CommandHandler
{
    public function __construct(private readonly EventStore $eventStore) {}

    public function handle(Command $command): Device
    {
        assert($command instanceof DisableDevice);

        $device = Device::query()->findOrFail($command->deviceId);
        // 未使用の一時ペアリングトークン(PairDeviceHandlerが発行)も含めて、この端末の
        // Sanctumトークンをすべて削除する。停止後に古い一時トークンでペアリングし直され
        // 復活してしまうことを防ぐ。
        $device->tokens()->delete();
        $device->status = DeviceStatus::DISABLED;
        $device->disabled_at = now();
        $device->save();

        $this->eventStore->append(
            aggregateType: 'device',
            aggregateId: (string) $device->id,
            event: new DeviceDisabled(deviceId: $device->id, disabledByUserId: $command->disabledByUserId),
        );

        return $device;
    }
}
