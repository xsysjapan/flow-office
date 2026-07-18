<?php

namespace App\Domain\DeviceAdminSession\Handlers;

use App\Domain\DeviceAdminSession\Commands\EndDeviceAdminSession;
use App\Domain\DeviceAdminSession\Events\DeviceAdminSessionEnded;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\DeviceAdminSession;
use Illuminate\Support\Carbon;

/**
 * UC-D006: 端末の管理者モードを終了する。
 *
 * @implements CommandHandler<EndDeviceAdminSession>
 */
class EndDeviceAdminSessionHandler implements CommandHandler
{
    public function __construct(private readonly EventStore $eventStore) {}

    public function handle(Command $command): DeviceAdminSession
    {
        assert($command instanceof EndDeviceAdminSession);

        $session = DeviceAdminSession::activeForDevice($command->deviceId);
        if ($session === null) {
            throw new DomainRuleException('この端末は現在管理者モードではありません。');
        }

        $session->ended_at = Carbon::now();
        $session->save();

        $this->eventStore->append(
            aggregateType: 'device_admin_session',
            aggregateId: (string) $session->id,
            event: new DeviceAdminSessionEnded(
                deviceAdminSessionId: $session->id,
                deviceId: $command->deviceId,
            ),
        );

        return $session;
    }
}
