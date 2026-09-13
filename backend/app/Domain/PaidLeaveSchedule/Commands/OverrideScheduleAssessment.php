<?php

namespace App\Domain\PaidLeaveSchedule\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 管理者による判定結果の上書き(依頼書§40「判定結果を上書き」)。理由は必須。
 */
class OverrideScheduleAssessment implements Command
{
    public function __construct(
        public readonly string $userId,
        public readonly string $scheduleEntryId,
        public readonly string $finalResult,
        public readonly string $reason,
        public readonly string $operatorUserId,
    ) {}
}
