<?php

namespace App\Domain\PaidLeave\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 管理者が発行済みの有給付与を取り消す。既に消化された分がある場合は取消不可
 * (RevokePaidLeaveGrantHandler参照)。
 */
class RevokePaidLeaveGrant implements Command
{
    public function __construct(
        public readonly string $grantId,
        public readonly string $revokedByUserId,
        public readonly ?string $reason,
    ) {}
}
