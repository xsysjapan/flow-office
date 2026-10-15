<?php

namespace App\Domain\PaidLeave\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 有給申請を取り消す。申請中・差戻し中・承認済みから取り消せる。
 *
 * isAdminAction=true は管理者による取消(申請者本人チェックを行わない)。
 * viaReactor=true はワークフロー側の取消(workflow_request.cancelled)からのReactor発行で、
 * 本人チェックを行わず、既に取消済みなら何もしない(冪等)。
 */
class CancelPaidLeaveRequest implements Command
{
    public function __construct(
        public readonly string $paidLeaveRequestId,
        public readonly string $cancelledByUserId,
        public readonly bool $isAdminAction = false,
        public readonly bool $viaReactor = false,
        public readonly ?string $initiatedByUserId = null,
    ) {}
}
