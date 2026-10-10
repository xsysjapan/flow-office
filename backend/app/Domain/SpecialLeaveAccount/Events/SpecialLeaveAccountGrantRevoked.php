<?php

namespace App\Domain\SpecialLeaveAccount\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * 未消化の付与を取り消す(消化済みの付与は取り消せない。SpecialLeaveAccountAggregate参照)。
 */
class SpecialLeaveAccountGrantRevoked extends ShouldBeStored
{
    public function __construct(
        public readonly string $grantId,
        public readonly string $revokedByUserId,
        public readonly ?string $reason,
    ) {}
}
