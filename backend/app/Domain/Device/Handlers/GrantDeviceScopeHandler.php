<?php

namespace App\Domain\Device\Handlers;

use App\Domain\Device\Commands\GrantDeviceScope;
use App\Domain\Device\Events\DeviceScopeGranted;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
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
    public function __construct(private readonly EventStore $eventStore) {}

    public function handle(Command $command): Device
    {
        assert($command instanceof GrantDeviceScope);

        if (! in_array($command->scope, DeviceScopeType::values(), true)) {
            throw new DomainRuleException('不正な端末スコープです。');
        }

        $device = Device::query()->findOrFail($command->deviceId);

        $device->scopes()->firstOrCreate(['scope' => $command->scope]);

        foreach ($device->tokens as $token) {
            $abilities = $token->abilities;
            if (! in_array('*', $abilities, true) && ! in_array($command->scope, $abilities, true)) {
                $token->forceFill(['abilities' => [...$abilities, $command->scope]])->save();
            }
        }

        $this->eventStore->append(
            aggregateType: 'device',
            aggregateId: (string) $device->id,
            event: new DeviceScopeGranted(
                deviceId: $device->id,
                scope: $command->scope,
                grantedByUserId: $command->grantedByUserId,
            ),
        );

        return $device->refresh()->load('roles', 'scopes');
    }
}
