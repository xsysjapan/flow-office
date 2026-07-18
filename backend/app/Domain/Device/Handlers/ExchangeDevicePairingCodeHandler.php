<?php

namespace App\Domain\Device\Handlers;

use App\Domain\Device\Commands\ExchangeDevicePairingCode;
use App\Domain\Device\Events\DevicePaired;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\Device;
use App\Models\DeviceStatus;
use Illuminate\Support\Facades\Hash;

/**
 * UC-D002: 端末アプリがペアリングコードをSanctumトークンへ交換する。
 *
 * @implements CommandHandler<ExchangeDevicePairingCode>
 */
class ExchangeDevicePairingCodeHandler implements CommandHandler
{
    public function __construct(private readonly EventStore $eventStore) {}

    /**
     * @return array{device: Device, plainTextToken: string}
     */
    public function handle(Command $command): array
    {
        assert($command instanceof ExchangeDevicePairingCode);

        $device = Device::query()->findOrFail($command->deviceId);

        // 停止・失効済みの端末はペアリングコードが(本来クリアされているはずでも)
        // 何らかの理由で残っていた場合に備え、statusでも明示的に拒否する
        // (disable/revoke後に古いペアリングコードで復活できてしまうことを防ぐ)。
        if ($device->status !== DeviceStatus::PENDING_PAIRING) {
            throw new DomainRuleException('この端末は現在ペアリング待ち状態ではありません。');
        }

        if ($device->pairing_code_hash === null || $device->pairing_code_expires_at === null) {
            throw new DomainRuleException('ペアリングコードが発行されていません。');
        }

        if (now()->gt($device->pairing_code_expires_at)) {
            throw new DomainRuleException('ペアリングコードの有効期限が切れています。');
        }

        if (! Hash::check($command->pairingCode, $device->pairing_code_hash)) {
            throw new DomainRuleException('ペアリングコードが一致しません。');
        }

        $device->load('roles', 'scopes');
        $abilities = $device->tokenAbilities();
        $plainTextToken = $device->createToken('device', $abilities)->plainTextToken;

        $device->status = DeviceStatus::ACTIVE;
        $device->paired_at = now();
        $device->pairing_code_hash = null;
        $device->pairing_code_expires_at = null;
        $device->save();

        $this->eventStore->append(
            aggregateType: 'device',
            aggregateId: (string) $device->id,
            event: new DevicePaired(deviceId: $device->id, abilities: $abilities),
        );

        return ['device' => $device, 'plainTextToken' => $plainTextToken];
    }
}
