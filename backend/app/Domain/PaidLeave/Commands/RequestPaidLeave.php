<?php

namespace App\Domain\PaidLeave\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-P003: 有給を申請する(有給申請の集約 PaidLeaveRequestAggregate を作る)。
 *
 * requestId(集約ID=有給申請ID)は呼び出し側(コントローラ/Reactor)が生成して渡す。
 * workflowRequestId は申請・承認文脈のワークフローと対応する場合に指定する(指定時は
 * 申請を`paid_leave_request.shared`として記録し、ワークフローの提出はWorkflow側のReactorが行う)。
 *
 * viaReactor=true(workflow_request.drafted からのReactor発行)のとき、同じ申請IDが既に
 * 申請されていれば何もしない(冪等)。initiatedByUserId は連鎖の起点の操作者(申請者)。
 */
class RequestPaidLeave implements Command
{
    public function __construct(
        public readonly string $requestId,
        public readonly string $userId,
        public readonly string $targetDate,
        public readonly string $leaveType,
        public readonly ?float $hours,
        public readonly string $approverUserId,
        public readonly ?string $reason,
        public readonly ?string $workflowRequestId = null,
        public readonly ?string $requestGroupId = null,
        public readonly bool $viaReactor = false,
        public readonly ?string $initiatedByUserId = null,
    ) {}
}
