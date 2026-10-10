<?php

namespace App\Domain\SpecialLeaveAccount\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 未消化の特別休暇の付与を取り消す(管理者の操作)。消化済みの分がある付与は取り消せない(口座集約が拒否する)。
 */
class RevokeSpecialLeaveAccountGrant implements Command
{
    public function __construct(
        public readonly string $userId,
        public readonly string $grantId,
        public readonly string $revokedByUserId,
        public readonly ?string $reason,
    ) {}
}
