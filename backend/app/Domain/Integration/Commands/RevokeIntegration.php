<?php

namespace App\Domain\Integration\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-I003: 連携を停止・削除する。
 */
class RevokeIntegration implements Command
{
    public function __construct(
        public readonly string $integrationId,
        public readonly int $revokedByUserId,
    ) {}
}
