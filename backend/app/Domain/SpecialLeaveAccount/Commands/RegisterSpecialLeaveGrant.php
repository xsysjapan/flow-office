<?php

namespace App\Domain\SpecialLeaveAccount\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 特別休暇の付与を登録する(人事担当者の付与・自動付与・移行後の付与の入口。口座集約へ記録する)。
 * grantIdは付与の集約ID(呼び出し側が生成する)。
 */
class RegisterSpecialLeaveGrant implements Command
{
    public function __construct(
        public readonly string $userId,
        public readonly string $grantId,
        public readonly int $specialLeaveTypeId,
        public readonly string $grantedOn,
        public readonly ?string $expiresOn,
        public readonly float $grantedDays,
        public readonly ?string $grantReason,
    ) {}
}
