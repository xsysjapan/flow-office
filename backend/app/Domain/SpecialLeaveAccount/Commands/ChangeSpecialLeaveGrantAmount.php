<?php

namespace App\Domain\SpecialLeaveAccount\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 特別休暇の付与日数を変更する(管理者の操作)。使用済みの日数を下回る日数・取消済みの付与は口座集約が拒否する。
 */
class ChangeSpecialLeaveGrantAmount implements Command
{
    public function __construct(
        public readonly string $userId,
        public readonly string $grantId,
        public readonly float $newGrantedDays,
        public readonly string $changedByUserId,
        public readonly ?string $reason,
    ) {}
}
