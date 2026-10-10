<?php

namespace App\Domain\SpecialLeaveAccount\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * 特別休暇の付与の登録(special_leave_account.grant_registered)。
 * 口座の集約IDは利用者から派生させるため、付与行の利用者IDをイベントに持つ(末尾。Projectorが使う)。
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
        public readonly ?string $userId = null,
    ) {}
}
