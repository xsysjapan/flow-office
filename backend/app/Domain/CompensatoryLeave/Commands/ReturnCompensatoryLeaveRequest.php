<?php

namespace App\Domain\CompensatoryLeave\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 代休申請を差し戻す(ワークフローの差戻しからのReactor発行が通常の経路)。
 * viaReactor=true のとき、既に差戻し中なら何もしない(冪等)。
 */
class ReturnCompensatoryLeaveRequest implements Command
{
    public function __construct(
        public readonly string $compensatoryLeaveRequestId,
        public readonly string $returnedByUserId,
        public readonly string $comment,
        public readonly bool $viaReactor = false,
        public readonly ?string $initiatedByUserId = null,
    ) {}
}
