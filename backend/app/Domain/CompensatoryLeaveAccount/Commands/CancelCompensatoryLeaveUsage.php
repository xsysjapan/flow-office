<?php

namespace App\Domain\CompensatoryLeaveAccount\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 代休申請の差戻し・取消に伴い消化記録を取り消す(承認済みは充当を解除し残数を戻す)。
 * viaReactor=true のとき、既に取消済みなら何もしない(冪等)。
 */
class CancelCompensatoryLeaveUsage implements Command
{
    public function __construct(
        public readonly string $userId,
        public readonly string $requestId,
        public readonly ?string $reason = null,
        public readonly bool $viaReactor = false,
        public readonly ?string $initiatedByUserId = null,
    ) {}
}
