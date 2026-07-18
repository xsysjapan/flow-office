<?php

namespace App\Domain\DeviceAdminSession\Handlers;

use App\Domain\AuthenticationKey\Services\AuthenticationKeyResolver;
use App\Domain\DeviceAdminSession\Commands\StartDeviceAdminSession;
use App\Domain\DeviceAdminSession\Services\DeviceAdminSessionOpener;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\Device;
use App\Models\DeviceAdminSession;
use App\Models\DeviceAdminSessionSource;
use App\Models\Role;

/**
 * UC-D006: 管理者本人のICカード(認証キー)をかざして端末を管理者モードにする。
 *
 * @implements CommandHandler<StartDeviceAdminSession>
 */
class StartDeviceAdminSessionHandler implements CommandHandler
{
    public function __construct(
        private readonly AuthenticationKeyResolver $resolver,
        private readonly DeviceAdminSessionOpener $opener,
    ) {}

    public function handle(Command $command): DeviceAdminSession
    {
        assert($command instanceof StartDeviceAdminSession);

        $device = Device::query()->findOrFail($command->deviceId);

        $key = $this->resolver->resolve($command->rawKeyValue, $device->id);
        $key->loadMissing('user');

        if (! $key->user->hasRole(Role::ADMIN)) {
            throw new DomainRuleException('この認証キーの持ち主は管理者ではないため、管理者モードにできません。');
        }

        return $this->opener->open($device, $key->user, DeviceAdminSessionSource::NFC_TAP, $key->id);
    }
}
