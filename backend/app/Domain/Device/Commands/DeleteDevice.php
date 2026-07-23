<?php

namespace App\Domain\Device\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-D005: 停止・失効済みの端末を一覧から論理削除する。
 */
class DeleteDevice implements Command
{
    public function __construct(
        public readonly string $deviceId,
        public readonly int $deletedByUserId,
    ) {}
}
