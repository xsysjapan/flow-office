<?php

namespace App\Domain\PaidLeave\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

/**
 * UC-P004 step5: 有効期限が近い付与分(paid_leave_grants)から消化する。
 * 1件の有給申請の承認が複数grantにまたがる場合、grantごとに1つ記録される。
 */
class PaidLeaveUsed implements DomainEvent
{
    public function __construct(
        public readonly int $paidLeaveUsageId,
        public readonly int $userId,
        public readonly int $paidLeaveGrantId,
        public readonly int $paidLeaveRequestId,
        public readonly string $usedOn,
        public readonly float $usedDays,
    ) {}

    public function eventType(): string
    {
        return 'paid_leave.used';
    }

    public function payload(): array
    {
        return [
            'paid_leave_usage_id' => $this->paidLeaveUsageId,
            'user_id' => $this->userId,
            'paid_leave_grant_id' => $this->paidLeaveGrantId,
            'paid_leave_request_id' => $this->paidLeaveRequestId,
            'used_on' => $this->usedOn,
            'used_days' => $this->usedDays,
        ];
    }
}
