<?php

namespace App\Domain\PaidLeaveAccount\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * Phase 5(cutover): usageType/paidLeaveRequestId/approverUserId/reason/requestGroupId/hours は
 * PaidLeaveAccountAggregate自体の不変条件には使わないが、
 * `App\Domain\PaidLeaveAccount\Projectors\PaidLeaveUsageAllocationProjector::createPaidLeaveRequestIfNeeded`が`paid_leave_requests`
 * (有給固有の申請パラメータ。docs/changesets/20260906-paid-leave-domain-redesign/spec.md
 * 論点10)をイベントから再生成できるようにするため、イベントにそのまま乗せて運ぶ。
 *
 * viaReactor=true(Reactorからの発行)の場合、同じ有給申請IDの有効な消化記録が既にあれば何もしない(冪等)。
 * initiatedByUserId は連鎖の起点となった操作者(システム処理ならnull)。
 */
class DesignatePaidLeaveUsage implements Command
{
    public function __construct(
        public readonly string $userId,
        public readonly ?string $workflowRequestId,
        public readonly ?string $attendanceDayId,
        public readonly string $usedOn,
        public readonly float $usedDays,
        public readonly string $usageType = 'full',
        public readonly ?string $paidLeaveRequestId = null,
        public readonly ?string $approverUserId = null,
        public readonly ?string $reason = null,
        public readonly ?string $requestGroupId = null,
        public readonly ?float $hours = null,
        public readonly bool $viaReactor = false,
        public readonly ?string $initiatedByUserId = null,
    ) {}
}
