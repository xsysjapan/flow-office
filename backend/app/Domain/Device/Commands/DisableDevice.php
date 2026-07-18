<?php

namespace App\Domain\Device\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-D005: 端末を一時停止する。
 */
class DisableDevice implements Command
{
    public function __construct(
        public readonly int $deviceId,
        public readonly int $disabledByUserId,
    ) {}
}
