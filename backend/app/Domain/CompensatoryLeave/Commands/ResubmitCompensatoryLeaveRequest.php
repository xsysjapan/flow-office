<?php

namespace App\Domain\CompensatoryLeave\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 差戻された代休申請を、同じ内容のまま再提出する(ワークフローの再提出からのReactor発行)。
 * viaReactor=true のとき、差戻し中でなければ何もしない(冪等)。
 */
class ResubmitCompensatoryLeaveRequest implements Command
{
    public function __construct(
        public readonly string $compensatoryLeaveRequestId,
        public readonly ?string $resubmittedByUserId,
        public readonly bool $viaReactor = false,
        public readonly ?string $initiatedByUserId = null,
    ) {}
}
