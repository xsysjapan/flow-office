<?php

namespace App\Domain\Device\Handlers;

use App\Domain\Device\Aggregates\DeviceAggregate;
use App\Domain\Device\Commands\RevokeDevice;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Models\Device;

/**
 * @implements CommandHandler<RevokeDevice>
 */
class RevokeDeviceHandler implements CommandHandler
{
    public function handle(Command $command): Device
    {
        assert($command instanceof RevokeDevice);

        $device = Device::query()->findOrFail($command->deviceId);
        // 未使用の一時ペアリングトークン(PairDeviceHandlerが発行)も含めて、この端末の
        // Sanctumトークンをすべて削除する。失効後に古い一時トークンでペアリングし直され
        // 復活してしまうことを防ぐ。
        $device->tokens()->delete();

        DeviceAggregate::retrieve($device->aggregate_uuid)
            ->revoke($command->revokedByUserId, $command->reason, now()->format('Y-m-d H:i:s'))
            ->persist();

        return $device->refresh();
    }
}
