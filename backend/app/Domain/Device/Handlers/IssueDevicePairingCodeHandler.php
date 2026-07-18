<?php

namespace App\Domain\Device\Handlers;

use App\Domain\Device\Commands\IssueDevicePairingCode;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\Device;
use App\Models\DeviceOwnerType;
use App\Models\DeviceStatus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * UC-D002: 共有端末のペアリングコードを発行する。コード自体は一度きりの短命な
 * セキュリティ材料でありstored_eventsには記録しない(system_settings/request_types と
 * 同じ「マスタ的な設定」の扱いに準ずる、docs/23-usecases-devices.md参照)。
 *
 * @implements CommandHandler<IssueDevicePairingCode>
 */
class IssueDevicePairingCodeHandler implements CommandHandler
{
    private const EXPIRES_IN_MINUTES = 15;

    /**
     * @return array{device: Device, pairingCode: string}
     */
    public function handle(Command $command): array
    {
        assert($command instanceof IssueDevicePairingCode);

        $device = Device::query()->findOrFail($command->deviceId);

        if ($device->owner_type !== DeviceOwnerType::ORGANIZATION_SHARED) {
            throw new DomainRuleException('共有端末以外にはペアリングコードを発行できません。');
        }

        $code = Str::upper(Str::random(8));

        $device->pairing_code_hash = Hash::make($code);
        $device->pairing_code_expires_at = now()->addMinutes(self::EXPIRES_IN_MINUTES);
        $device->status = DeviceStatus::PENDING_PAIRING;
        $device->save();

        return ['device' => $device, 'pairingCode' => $code];
    }
}
