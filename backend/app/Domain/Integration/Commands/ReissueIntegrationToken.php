<?php

namespace App\Domain\Integration\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-I003: アクセストークンを再発行する(既存トークンは失効させ、同じスコープで発行し直す)。
 */
class ReissueIntegrationToken implements Command
{
    public function __construct(
        public readonly string $integrationId,
        public readonly int $reissuedByUserId,
    ) {}
}
