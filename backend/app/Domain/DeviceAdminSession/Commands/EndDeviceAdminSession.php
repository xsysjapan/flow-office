<?php

namespace App\Domain\DeviceAdminSession\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-D006: 端末の管理者モードを終了する。
 */
class EndDeviceAdminSession implements Command
{
    public function __construct(
        public readonly string $deviceId,
    ) {}
}
