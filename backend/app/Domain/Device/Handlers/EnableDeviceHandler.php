<?php

namespace App\Domain\Device\Handlers;

use App\Domain\Device\Aggregates\DeviceAggregate;
use App\Domain\Device\Commands\EnableDevice;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\Device;
use App\Models\DeviceStatus;

/**
 * UC-D005: 停止(disabled)中の端末を有効化し、pending_pairingへ戻す。失効(revoked)済みの
 * 端末は対象外(UC-D001/UC-D002から登録し直す)。有効化後は既存の
 * IssueDevicePairingClaimHandler(POST /devices/{device}/pairing)で通常通り再ペアリングする。
 *
 * @implements CommandHandler<EnableDevice>
 */
class EnableDeviceHandler implements CommandHandler
{
    public function handle(Command $command): Device
    {
        assert($command instanceof EnableDevice);

        $device = Device::query()->findOrFail($command->deviceId);

        if ($device->status !== DeviceStatus::DISABLED) {
            throw new DomainRuleException('停止中の端末のみ有効化できます。');
        }

        DeviceAggregate::retrieve($device->id)
            ->enable($command->enabledByUserId, now()->format('Y-m-d H:i:s'))
            ->persist();

        return $device->refresh();
    }
}
