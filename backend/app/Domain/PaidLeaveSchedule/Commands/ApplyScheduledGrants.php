<?php

namespace App\Domain\PaidLeaveSchedule\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 管理者の一括付与操作(依頼書§39)。`userId`は対象社員(Schedule Aggregateは
 * userId単位のため、一括付与対象は同一社員内の複数エントリという想定。複数社員を
 * またぐ一括付与はHandler呼び出し元(Phase D管理API)がユーザーごとに束ねてこの
 * Commandを複数回発行する)。
 */
class ApplyScheduledGrants implements Command
{
    /**
     * @param  array<int, string>  $entryIds
     */
    public function __construct(
        public readonly string $userId,
        public readonly array $entryIds,
        public readonly string $operatorUserId,
    ) {}
}
