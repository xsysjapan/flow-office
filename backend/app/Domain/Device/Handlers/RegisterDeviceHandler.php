<?php

namespace App\Domain\Device\Handlers;

use App\Domain\Device\Commands\RegisterDevice;
use App\Domain\Device\Events\DevicePaired;
use App\Domain\Device\Events\DeviceRegistered;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\Device;
use App\Models\DeviceOwnerType;
use App\Models\DeviceStatus;

/**
 * UC-D001/UC-D003: 端末を登録する。
 * 個人端末(owner_type=personal)は、既にログイン済みの本人操作であるため、UC-D002の
 * ペアリングコード交換を経由せずこのHandler内で即座にSanctumトークンを発行し有効化する。
 * 共有端末(owner_type=organization_shared)はpending_pairingのまま作成し、
 * IssueDevicePairingCode/ExchangeDevicePairingCodeで別途ペアリングする。
 *
 * @implements CommandHandler<RegisterDevice>
 */
class RegisterDeviceHandler implements CommandHandler
{
    public function __construct(private readonly EventStore $eventStore) {}

    /**
     * @return array{device: Device, plainTextToken: ?string}
     */
    public function handle(Command $command): array
    {
        assert($command instanceof RegisterDevice);

        if (! in_array($command->ownerType, DeviceOwnerType::values(), true)) {
            throw new DomainRuleException('不正な端末所有区分です。');
        }

        if ($command->ownerType === DeviceOwnerType::PERSONAL && $command->ownerUserId === null) {
            throw new DomainRuleException('個人端末には所有者(ownerUserId)が必須です。');
        }

        $device = Device::query()->create([
            'owner_type' => $command->ownerType,
            'owner_user_id' => $command->ownerUserId,
            'name' => $command->name,
            'device_type' => $command->deviceType,
            'status' => DeviceStatus::PENDING_PAIRING,
            'site_id' => $command->siteId,
            'location_name' => $command->locationName,
            'default_work_location_type' => $command->defaultWorkLocationType,
            'timezone' => $command->timezone,
            'allowed_punch_types' => $command->allowedPunchTypes,
            'allow_offline' => $command->allowOffline,
            'require_location' => $command->requireLocation,
            'auto_detect_punch_type' => $command->autoDetectPunchType,
        ]);

        foreach ($command->roleTypes as $roleType) {
            $device->roles()->create(['role_type' => $roleType]);
        }

        $this->eventStore->append(
            aggregateType: 'device',
            aggregateId: (string) $device->id,
            event: new DeviceRegistered(
                deviceId: $device->id,
                ownerType: $command->ownerType,
                ownerUserId: $command->ownerUserId,
                name: $command->name,
                deviceType: $command->deviceType,
                registeredByUserId: $command->registeredByUserId,
            ),
        );

        $plainTextToken = null;

        if ($command->ownerType === DeviceOwnerType::PERSONAL) {
            $device->load('roles', 'scopes');
            $abilities = $device->tokenAbilities();
            $plainTextToken = $device->createToken('device', $abilities)->plainTextToken;

            $device->status = DeviceStatus::ACTIVE;
            $device->paired_at = now();
            $device->save();

            $this->eventStore->append(
                aggregateType: 'device',
                aggregateId: (string) $device->id,
                event: new DevicePaired(deviceId: $device->id, abilities: $abilities),
            );
        }

        return ['device' => $device->refresh()->load('roles'), 'plainTextToken' => $plainTextToken];
    }
}
