<?php

namespace App\Domain\Device\Handlers;

use App\Domain\Device\Aggregates\DeviceAggregate;
use App\Domain\Device\Commands\UpdateDeviceSettings;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Models\Device;

/**
 * 端末の設置場所などの設定を変更する。稼働状態(active/disabled/revoked)・役割・スコープは
 * それぞれ専用のCommand(DisableDevice/RevokeDevice/GrantDeviceScope)で扱うため対象外。
 *
 * @implements CommandHandler<UpdateDeviceSettings>
 */
class UpdateDeviceSettingsHandler implements CommandHandler
{
    public function handle(Command $command): Device
    {
        assert($command instanceof UpdateDeviceSettings);

        $device = Device::query()->findOrFail($command->deviceId);

        DeviceAggregate::retrieve($device->id)
            ->updateSettings(
                name: $command->name,
                siteId: $command->siteId,
                locationName: $command->locationName,
                defaultWorkLocationType: $command->defaultWorkLocationType,
                timezone: $command->timezone,
                allowedPunchTypes: $command->allowedPunchTypes,
                allowOffline: $command->allowOffline,
                requireLocation: $command->requireLocation,
                autoDetectPunchType: $command->autoDetectPunchType,
                updatedByUserId: $command->updatedByUserId,
            )
            ->persist();

        return $device->refresh()->load('roles', 'scopes');
    }
}
