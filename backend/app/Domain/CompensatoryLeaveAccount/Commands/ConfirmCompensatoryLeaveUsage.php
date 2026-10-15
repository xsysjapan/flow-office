<?php

namespace App\Domain\CompensatoryLeaveAccount\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 代休申請の承認に伴い消化記録を確定する(付与への充当。残数不足でも確定し未充当量を記録する。論点17)。
 * viaReactor=true(承認からのReactor発行)のとき、既に確定済みなら何もしない(冪等)。
 */
class ConfirmCompensatoryLeaveUsage implements Command
{
    public function __construct(
        public readonly string $userId,
        public readonly string $requestId,
        public readonly bool $viaReactor = false,
        public readonly ?string $initiatedByUserId = null,
    ) {}
}
