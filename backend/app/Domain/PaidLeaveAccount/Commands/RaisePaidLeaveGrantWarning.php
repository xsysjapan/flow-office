<?php

namespace App\Domain\PaidLeaveAccount\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-P005/UC-P006。旧`App\Domain\PaidLeave\Aggregates\PaidLeaveGrantAggregate::raiseWarning`
 * 廃止(Phase 5 cutover)に伴う置き換え。警告済みフラグの記録のみで残高等の不変条件には
 * 関与しないため、`PaidLeaveAccountAggregate`側もバリデーション無しでそのまま記録する。
 */
class RaisePaidLeaveGrantWarning implements Command
{
    public function __construct(
        public readonly string $userId,
        public readonly string $grantId,
        public readonly string $warningType,
        public readonly string $message,
    ) {}
}
