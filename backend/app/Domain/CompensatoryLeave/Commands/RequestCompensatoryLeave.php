<?php

namespace App\Domain\CompensatoryLeave\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 代休を申請する(代休申請の集約を作る)。
 *
 * requestIdは集約ID(=申請ID)で、呼び出し側(コントローラ/Reactor)が生成して渡す。
 * workflowRequestIdは申請・承認文脈のワークフローと対応する場合に指定する(指定時は申請を
 * `compensatory_leave.request_shared`として記録し、ワークフローの提出はWorkflow側のReactorが行う)。
 * viaReactor=true(workflow_request.drafted からのReactor発行)のとき、同じ申請IDが既に申請済みなら何もしない(冪等)。
 */
class RequestCompensatoryLeave implements Command
{
    public function __construct(
        public readonly string $userId,
        public readonly string $targetDate,
        public readonly string $leaveType,
        public readonly ?float $hours,
        public readonly string $approverUserId,
        public readonly ?string $reason,
        public readonly ?string $workflowRequestId = null,
        public readonly ?string $requestId = null,
        public readonly ?string $requestGroupId = null,
        public readonly bool $viaReactor = false,
        public readonly ?string $initiatedByUserId = null,
    ) {}
}
