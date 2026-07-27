<?php

namespace App\Domain\Device\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-D005: 停止(disabled)中の端末を有効化し、pending_pairingへ戻す。
 */
class EnableDevice implements Command
{
    public function __construct(
        public readonly string $deviceId,
        public readonly string $enabledByUserId,
    ) {}
}
