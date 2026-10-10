<?php

namespace App\Domain\SpecialLeave\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 差戻された特別休暇申請を、同じ内容のまま再提出する(ワークフローの再提出からのReactor発行)。
 * viaReactor=true のとき、差戻し中でなければ何もしない(冪等)。
 */
class ResubmitSpecialLeaveRequest implements Command
{
    public function __construct(
        public readonly string $specialLeaveRequestId,
        public readonly ?string $resubmittedByUserId,
        public readonly bool $viaReactor = false,
        public readonly ?string $initiatedByUserId = null,
    ) {}
}
