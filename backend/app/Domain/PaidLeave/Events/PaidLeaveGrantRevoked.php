<?php

namespace App\Domain\PaidLeave\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * paid_leave.grant_revoked
 *
 * 管理者が未消化の有給付与を取り消した(RevokePaidLeaveGrantHandler参照)。
 */
class PaidLeaveGrantRevoked extends ShouldBeStored
{
    public function __construct(
        public readonly string $revokedByUserId,
        public readonly ?string $reason,
    ) {}
}
