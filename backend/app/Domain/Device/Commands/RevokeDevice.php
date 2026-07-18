<?php

namespace App\Domain\Device\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-D005: 端末を紛失・盗難等により恒久的に失効させる。
 */
class RevokeDevice implements Command
{
    public function __construct(
        public readonly int $deviceId,
        public readonly int $revokedByUserId,
        public readonly ?string $reason,
    ) {}
}
