<?php

namespace App\Domain\Device\Handlers;

use App\Domain\Device\Aggregates\DeviceAggregate;
use App\Domain\Device\Commands\RegisterDevice;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\Device;
use App\Models\DeviceOwnerType;
use Illuminate\Support\Str;

/**
 * UC-D001/UC-D003: 端末を登録する。
 * 個人端末(owner_type=personal)は、既にログイン済みの本人操作であるため、UC-D002の
 * ペアリングコード交換を経由せずこのHandler内で即座にSanctumトークンを発行し有効化する。
 * 共有端末(owner_type=organization_shared)はpending_pairingのまま作成し、
 * IssueDevicePairingClaim/ClaimDevicePairingで別途ペアリングする。
 *
 * @implements CommandHandler<RegisterDevice>
 */
class RegisterDeviceHandler implements CommandHandler
{
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

        $aggregateUuid = (string) Str::uuid();

        $aggregate = DeviceAggregate::retrieve($aggregateUuid)->register(
            ownerType: $command->ownerType,
            ownerUserId: $command->ownerUserId,
            name: $command->name,
            deviceType: $command->deviceType,
            roleTypes: $command->roleTypes,
            siteId: $command->siteId,
            locationName: $command->locationName,
            defaultWorkLocationType: $command->defaultWorkLocationType,
            timezone: $command->timezone,
            allowedPunchTypes: $command->allowedPunchTypes,
            allowOffline: $command->allowOffline,
            requireLocation: $command->requireLocation,
            autoDetectPunchType: $command->autoDetectPunchType,
            registeredByUserId: $command->registeredByUserId,
        );
        $aggregate->persist();

        $device = Device::query()->findOrFail($aggregateUuid);

        $plainTextToken = null;

        if ($command->ownerType === DeviceOwnerType::PERSONAL) {
            $device->load('roles', 'scopes');
            $abilities = $device->tokenAbilities();
            $plainTextToken = $device->createToken('device', $abilities)->plainTextToken;

            $aggregate->pair($abilities, now()->format('Y-m-d H:i:s'))->persist();
        }

        return ['device' => $device->refresh()->load('roles'), 'plainTextToken' => $plainTextToken];
    }
}
