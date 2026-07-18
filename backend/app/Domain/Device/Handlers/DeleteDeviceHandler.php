<?php

namespace App\Domain\Device\Handlers;

use App\Domain\Device\Commands\DeleteDevice;
use App\Domain\Device\Events\DeviceDeleted;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\Device;
use App\Models\DeviceStatus;

/**
 * @implements CommandHandler<DeleteDevice>
 */
class DeleteDeviceHandler implements CommandHandler
{
    public function __construct(private readonly EventStore $eventStore) {}

    public function handle(Command $command): Device
    {
        assert($command instanceof DeleteDevice);

        $device = Device::query()->findOrFail($command->deviceId);

        if (! in_array($device->status, [DeviceStatus::DISABLED, DeviceStatus::REVOKED], true)) {
            throw new DomainRuleException('稼働中またはペアリング待ちの端末は削除できません。先に停止または失効させてください。');
        }

        $this->eventStore->append(
            aggregateType: 'device',
            aggregateId: (string) $device->id,
            event: new DeviceDeleted(
                deviceId: $device->id,
                deletedByUserId: $command->deletedByUserId,
            ),
        );

        // 監査証跡(stored_events、UC-M003)は残すため物理削除はせず、論理削除のみ行う。
        $device->delete();

        return $device;
    }
}
