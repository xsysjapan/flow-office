<?php

namespace App\Domain\UserManagement\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 有給の自動付与をユーザーごとに有効/無効化する
 * (docs/changesets/20260904-paid-leave-auto-grant-per-user-toggle/spec.md)。
 */
class SetPaidLeaveAutoGrantEnabled implements Command
{
    public function __construct(
        public readonly string $userId,
        public readonly bool $enabled,
        public readonly string $changedByUserId,
    ) {}
}
