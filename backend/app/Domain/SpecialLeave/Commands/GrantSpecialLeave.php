<?php

namespace App\Domain\SpecialLeave\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 特別休暇を付与する(人事担当者が対象者・種別・日数・失効日を指定して都度実行)。
 * 有給と異なり法定の時効がないため、$expiresOnはnullable(null=失効しない)。
 */
class GrantSpecialLeave implements Command
{
    public function __construct(
        public readonly string $userId,
        public readonly int $specialLeaveTypeId,
        public readonly string $grantedOn,
        public readonly ?string $expiresOn,
        public readonly float $grantedDays,
        public readonly ?string $grantReason,
    ) {}
}
