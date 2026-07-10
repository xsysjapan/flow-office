<?php

namespace App\Domain\BackOffice\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-B003: 処理ステータスを更新する。
 */
class ChangeBackOfficeTaskStatus implements Command
{
    public function __construct(
        public readonly int $backOfficeTaskId,
        public readonly string $newStatus,
        public readonly int $changedByUserId,
        public readonly ?string $comment = null,
    ) {}
}
