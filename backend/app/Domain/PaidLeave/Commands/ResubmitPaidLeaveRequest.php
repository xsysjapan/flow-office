<?php

namespace App\Domain\PaidLeave\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 差戻された有給申請を、同じ内容のまま再提出する(ワークフローの再提出からのReactor発行)。
 * viaReactor=true のとき、差戻し中でなければ何もしない(冪等)。
 */
class ResubmitPaidLeaveRequest implements Command
{
    public function __construct(
        public readonly string $paidLeaveRequestId,
        public readonly ?string $resubmittedByUserId,
        public readonly bool $viaReactor = false,
        public readonly ?string $initiatedByUserId = null,
    ) {}
}
