<?php

namespace App\Domain\SpecialLeave\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * special_leave.grant_revoked
 *
 * 管理者が未消化の特別休暇付与を取り消した(RevokeSpecialLeaveGrantHandler参照)。
 */
class SpecialLeaveGrantRevoked extends ShouldBeStored
{
    public function __construct(
        public readonly string $revokedByUserId,
        public readonly ?string $reason,
    ) {}
}
