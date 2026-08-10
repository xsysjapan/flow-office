<?php

namespace App\Domain\UserManagement\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 利用開始日を設定する(勤怠提出フォロー等の各種フォロー通知の起算日として使う)。
 */
class SetUserUsageStartDate implements Command
{
    public function __construct(
        public readonly string $userId,
        public readonly string $usageStartDate,
        public readonly string $changedByUserId,
    ) {}
}
