<?php

namespace App\Domain\CompensatoryLeaveAccount\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * 未使用の確定済み付与を取り消す。消化済みの付与は取り消せない(CompensatoryLeaveAccountAggregate::cancelGrant参照)。
 */
class CompensatoryLeaveAccountGrantCancelled extends ShouldBeStored
{
    public function __construct(
        public readonly string $userId,
        public readonly string $grantId,
        public readonly string $cancelledByUserId,
        public readonly ?string $reason,
    ) {}
}
