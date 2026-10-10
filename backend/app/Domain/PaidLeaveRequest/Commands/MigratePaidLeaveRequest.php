<?php

namespace App\Domain\PaidLeaveRequest\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 本変更前に申請された有給申請の現在状態を有給申請の集約へ引き継ぐ(運用コマンド paid-leave:migrate-requests)。
 * 申請IDの集約が未作成(status=none)のときだけ`paid_leave_request.migrated`を記録する(既に状態があれば何もしない)。
 *
 * status は引き継ぎ時点の状態(submitted/returned/approved/cancelled)。usageId は対応する消化記録ID(無ければnull)。
 * hasUsage=false かつ approved の申請は cutover前の申請で、集約が取消を拒否する。
 */
class MigratePaidLeaveRequest implements Command
{
    public function __construct(
        public readonly string $paidLeaveRequestId,
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
        public readonly ?string $submittedAt = null,
        public readonly ?string $approvedAt = null,
        public readonly ?string $returnedAt = null,
        public readonly ?string $cancelledAt = null,
    ) {}
}
