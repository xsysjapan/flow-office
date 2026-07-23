<?php

namespace App\Domain\Device\Handlers;

use App\Domain\Device\Aggregates\DeviceAggregate;
use App\Domain\Device\Commands\ClaimDevicePairing;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\Device;
use App\Models\DeviceStatus;

/**
 * UC-D002: 一時ペアリングトークン(claim token)を、業務用の本トークンに交換する。
 * ルートは`auth:sanctum`+`ability:device:claim-pairing`で保護されており、ここに
 * 到達した時点で呼び出し元(claim tokenの持ち主)は既に検証済み。
 *
 * @implements CommandHandler<ClaimDevicePairing>
 */
class ClaimDevicePairingHandler implements CommandHandler
{
    /**
     * @return array{device: Device, plainTextToken: string}
     */
    public function handle(Command $command): array
    {
        assert($command instanceof ClaimDevicePairing);

        $device = Device::query()->findOrFail($command->deviceId);

        if ($device->status !== DeviceStatus::PENDING_PAIRING) {
            throw new DomainRuleException('この端末は現在ペアリング待ち状態ではありません。');
        }

        $device->load('roles', 'scopes');
        $abilities = $device->tokenAbilities();

        // claim token(device:claim-pairingのみのability)を含め、この端末の
        // Sanctumトークンをすべて入れ替える(一時トークンの使い捨て)。
        $device->tokens()->delete();
        $plainTextToken = $device->createToken('device', $abilities)->plainTextToken;

        DeviceAggregate::retrieve($device->aggregate_uuid)
            ->pair($abilities, now()->format('Y-m-d H:i:s'))
            ->persist();

        return ['device' => $device->refresh(), 'plainTextToken' => $plainTextToken];
    }
}
