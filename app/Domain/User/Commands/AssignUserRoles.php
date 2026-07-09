<?php

namespace App\Domain\User\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-M001: 権限を設定する。
 */
class AssignUserRoles implements Command
{
    /**
     * @param  array<int, string>  $roleCodes
     */
    public function __construct(
        public readonly int $userId,
        public readonly array $roleCodes,
        public readonly int $changedByUserId,
    ) {}
}
