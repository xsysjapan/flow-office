<?php

namespace App\Domain\SpecialLeave\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

/**
 * 失効日が近い付与分(special_leave_grants、無期限は最後)から消化する。1件の特別休暇申請の
 * 承認が複数grantにまたがる場合、grantごとに1つ記録される。
 */
class SpecialLeaveUsed implements DomainEvent
{
    public function __construct(
        public readonly int $specialLeaveUsageId,
        public readonly int $userId,
        public readonly int $specialLeaveGrantId,
        public readonly int $specialLeaveRequestId,
        public readonly string $usedOn,
        public readonly float $usedDays,
    ) {}

    public function eventType(): string
    {
        return 'special_leave.used';
    }

    public function payload(): array
    {
        return [
            'special_leave_usage_id' => $this->specialLeaveUsageId,
            'user_id' => $this->userId,
            'special_leave_grant_id' => $this->specialLeaveGrantId,
            'special_leave_request_id' => $this->specialLeaveRequestId,
            'used_on' => $this->usedOn,
            'used_days' => $this->usedDays,
        ];
    }
}
