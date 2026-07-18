<?php

namespace App\Domain\Device\Handlers;

use App\Domain\Device\Commands\UpdateDeviceSettings;
use App\Domain\Device\Events\DeviceSettingsUpdated;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Models\Device;

/**
 * 端末の設置場所などの設定を変更する。稼働状態(active/disabled/revoked)・役割・スコープは
 * それぞれ専用のCommand(DisableDevice/RevokeDevice/GrantDeviceScope)で扱うため対象外。
 *
 * @implements CommandHandler<UpdateDeviceSettings>
 */
class UpdateDeviceSettingsHandler implements CommandHandler
{
    public function __construct(private readonly EventStore $eventStore) {}

    public function handle(Command $command): Device
    {
        assert($command instanceof UpdateDeviceSettings);

        $device = Device::query()->findOrFail($command->deviceId);

        $device->fill([
            'name' => $command->name,
            'site_id' => $command->siteId,
            'location_name' => $command->locationName,
            'default_work_location_type' => $command->defaultWorkLocationType,
            'timezone' => $command->timezone,
            'allowed_punch_types' => $command->allowedPunchTypes,
            'allow_offline' => $command->allowOffline,
            'require_location' => $command->requireLocation,
            'auto_detect_punch_type' => $command->autoDetectPunchType,
        ])->save();

        $this->eventStore->append(
            aggregateType: 'device',
            aggregateId: (string) $device->id,
            event: new DeviceSettingsUpdated(
                deviceId: $device->id,
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
            ),
        );

        return $device->refresh()->load('roles', 'scopes');
    }
}
