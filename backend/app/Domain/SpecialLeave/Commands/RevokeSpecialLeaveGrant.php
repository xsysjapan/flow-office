<?php

namespace App\Domain\SpecialLeave\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 管理者が発行済みの特別休暇付与を取り消す。既に消化された分がある場合は取消不可
 * (RevokeSpecialLeaveGrantHandler参照)。
 */
class RevokeSpecialLeaveGrant implements Command
{
    public function __construct(
        public readonly string $grantId,
        public readonly string $revokedByUserId,
        public readonly ?string $reason,
    ) {}
}
