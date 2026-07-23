<?php

namespace App\Domain\Device\Handlers;

use App\Domain\Device\Aggregates\DeviceAggregate;
use App\Domain\Device\Commands\GrantDeviceScope;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\Device;
use App\Models\DeviceScopeType;

/**
 * UC-D004: 外部端末にAPIスコープを付与する。既に発行済みのトークンがある場合は
 * abilitiesへ即座にマージする(スコープ付与のたびに再ペアリングを要求しないため)。
 *
 * @implements CommandHandler<GrantDeviceScope>
 */
class GrantDeviceScopeHandler implements CommandHandler
{
    public function handle(Command $command): Device
    {
        assert($command instanceof GrantDeviceScope);

        if (! in_array($command->scope, DeviceScopeType::values(), true)) {
            throw new DomainRuleException('不正な端末スコープです。');
        }

        $device = Device::query()->findOrFail($command->deviceId);

        DeviceAggregate::retrieve($device->aggregate_uuid)
            ->grantScope($command->scope, $command->grantedByUserId)
            ->persist();

        $device->refresh();

        foreach ($device->tokens as $token) {
            $abilities = $token->abilities;
            if (! in_array('*', $abilities, true) && ! in_array($command->scope, $abilities, true)) {
                $token->forceFill(['abilities' => [...$abilities, $command->scope]])->save();
            }
        }

        return $device->load('roles', 'scopes');
    }
}
