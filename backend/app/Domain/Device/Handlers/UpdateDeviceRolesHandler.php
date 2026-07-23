<?php

namespace App\Domain\Device\Handlers;

use App\Domain\Device\Aggregates\DeviceAggregate;
use App\Domain\Device\Commands\UpdateDeviceRoles;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\Device;
use App\Models\DeviceRoleType;

/**
 * 共有端末の役割(device_roles)を、登録時と同じ選択肢の集合に入れ替える。役割由来の
 * ability(`recorder:punch`等)が変わるため、既に発行済みのトークンがあれば
 * abilitiesを再計算して差し替える(GrantDeviceScopeHandlerと同様、再ペアリングを要求しない)。
 *
 * @implements CommandHandler<UpdateDeviceRoles>
 */
class UpdateDeviceRolesHandler implements CommandHandler
{
    public function handle(Command $command): Device
    {
        assert($command instanceof UpdateDeviceRoles);

        if (empty($command->roleTypes)) {
            throw new DomainRuleException('端末には少なくとも1つの役割が必要です。');
        }

        foreach ($command->roleTypes as $roleType) {
            if (! in_array($roleType, DeviceRoleType::values(), true)) {
                throw new DomainRuleException('不正な端末役割です。');
            }
        }

        $device = Device::query()->findOrFail($command->deviceId);

        DeviceAggregate::retrieve($device->aggregate_uuid)
            ->assignRoles($command->roleTypes, $command->updatedByUserId)
            ->persist();

        $device->refresh()->load('roles');
        $abilities = $device->tokenAbilities();
        foreach ($device->tokens as $token) {
            // 一時ペアリングトークン(device:claim-pairingのみのability)は対象外にする。
            if ($token->name === 'device-pairing-claim') {
                continue;
            }
            $token->forceFill(['abilities' => $abilities])->save();
        }

        return $device->load('roles', 'scopes');
    }
}
