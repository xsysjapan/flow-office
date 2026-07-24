<?php

namespace App\Domain\DeviceAdminSession\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-D006: 管理者本人のICカード(認証キー)をかざして端末を管理者モードにする。
 */
class StartDeviceAdminSession implements Command
{
    public function __construct(
        public readonly string $deviceId,
        public readonly string $rawKeyValue,
    ) {}
}
