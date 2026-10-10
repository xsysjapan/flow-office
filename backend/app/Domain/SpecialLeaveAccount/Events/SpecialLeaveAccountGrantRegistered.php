<?php

namespace App\Domain\SpecialLeaveAccount\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * 利用者の特別休暇口座に付与を登録する。`expiresOn`がnullの付与は失効しない(無期限)。
 */
class SpecialLeaveAccountGrantRegistered extends ShouldBeStored
{
    public function __construct(
        public readonly string $grantId,
        public readonly int $specialLeaveTypeId,
        public readonly string $grantedOn,
        public readonly ?string $expiresOn,
        public readonly float $grantedDays,
        public readonly ?string $grantReason,
    ) {}
}
