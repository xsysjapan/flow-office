<?php

namespace App\Domain\User\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 入社日を設定する (docs/09-usecases-paid-leave.md UC-P002: 継続勤務期間の計算に使う)。
 */
class SetUserHireDate implements Command
{
    public function __construct(
        public readonly string $userId,
        public readonly string $hireDate,
        public readonly string $changedByUserId,
    ) {}
}
