<?php

namespace App\Domain\PaidLeaveRequest\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * 本変更前に申請された有給申請の現在状態の引き継ぎ(paid_leave_request.migrated)。
 * status は引き継ぎ時点の状態(submitted/returned/approved/cancelled)。usageId は対応する消化記録ID
 * (無い場合null)。hasUsage=false かつ approved の申請は cutover前の申請で、取消できない。
 */
class PaidLeaveRequestLifecycleMigrated extends ShouldBeStored
{
    public function __construct(
        public readonly string $userId,
        public readonly string $targetDate,
        public readonly string $leaveType,
        public readonly ?float $hours,
        public readonly float $requestedDays,
        public readonly ?string $approverUserId,
        public readonly ?string $reason,
        public readonly ?string $requestGroupId,
        public readonly ?string $workflowRequestId,
        public readonly string $status,
        public readonly ?string $usageId,
        public readonly bool $hasUsage,
    ) {}
}
