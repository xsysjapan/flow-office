<?php

namespace App\Domain\DeviceAdminSession\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-D006: 端末アクティベーション直後、管理者ICカードがまだ登録されていない(または
 * 未確認の)場合のブートストラップ経路。ペアリングを行った管理者(`activated_by_user_id`)
 * に紐づく場合は本人を、紐づかない場合は`targetAdminUserId`で指定された管理者を対象にする。
 */
class StartDeviceAdminSessionBootstrap implements Command
{
    public function __construct(
        public readonly string $deviceId,
        public readonly ?string $targetAdminUserId,
    ) {}
}
