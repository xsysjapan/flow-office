<?php

namespace App\Domain\Device\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-D005: 端末を紛失・盗難等により恒久的に失効させる。
 */
class RevokeDevice implements Command
{
    public function __construct(
        public readonly string $deviceId,
        public readonly string $revokedByUserId,
        public readonly ?string $reason,
    ) {}
}
