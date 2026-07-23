<?php

namespace App\Domain\Device\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-D004: 外部端末にAPIスコープを付与する。
 */
class GrantDeviceScope implements Command
{
    public function __construct(
        public readonly string $deviceId,
        public readonly string $scope,
        public readonly int $grantedByUserId,
    ) {}
}
