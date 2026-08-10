<?php

namespace App\Domain\PaidLeave\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-P003: 有給を申請する。
 *
 * workflow_request経由の申請の場合、workflowRequestIdを指定して、
 * RequestPaidLeaveHandlerが PaidLeaveRequestShared を発行して
 * SubmitWorkflowRequestOnPaidLeaveRequestSharedReactor へ繋ぐ。
 */
class RequestPaidLeave implements Command
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
    ) {}
}
