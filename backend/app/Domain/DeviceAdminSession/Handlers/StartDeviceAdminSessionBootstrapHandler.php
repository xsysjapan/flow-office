<?php

namespace App\Domain\DeviceAdminSession\Handlers;

use App\Domain\DeviceAdminSession\Commands\StartDeviceAdminSessionBootstrap;
use App\Domain\DeviceAdminSession\Services\DeviceAdminSessionOpener;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\Device;
use App\Models\DeviceAdminSession;
use App\Models\DeviceAdminSessionSource;
use App\Models\Role;
use App\Models\User;

/**
 * UC-D006: 端末アクティベーション直後、管理者ICカードがまだ登録・確認できない場合の
 * ブートストラップ経路。この端末をペアリングした管理者(`activated_by_user_id`)が
 * いればその本人を、いなければ`targetAdminUserId`で明示的に選択された管理者を対象にする。
 *
 * @implements CommandHandler<StartDeviceAdminSessionBootstrap>
 */
class StartDeviceAdminSessionBootstrapHandler implements CommandHandler
{
    public function __construct(private readonly DeviceAdminSessionOpener $opener) {}

    public function handle(Command $command): DeviceAdminSession
    {
        assert($command instanceof StartDeviceAdminSessionBootstrap);

        $device = Device::query()->findOrFail($command->deviceId);
        $device->loadMissing('activatedByUser.roles');

        $adminUser = $this->resolveTargetAdmin($device, $command->targetAdminUserId);

        return $this->opener->open($device, $adminUser, DeviceAdminSessionSource::BOOTSTRAP, null);
    }

    private function resolveTargetAdmin(Device $device, ?string $targetAdminUserId): User
    {
        $activatedBy = $device->activatedByUser;
        if ($activatedBy !== null && $activatedBy->hasRole(Role::ADMIN)) {
            return $activatedBy;
        }

        if ($targetAdminUserId === null) {
            throw new DomainRuleException('登録対象の管理者を選択してください。');
        }

        $targetAdmin = User::query()->find($targetAdminUserId);
        if ($targetAdmin === null || ! $targetAdmin->hasRole(Role::ADMIN)) {
            throw new DomainRuleException('指定されたユーザーは管理者ではありません。');
        }

        return $targetAdmin;
    }
}
