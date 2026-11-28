<?php

namespace App\Domain\PaidLeaveSchedule\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 付与予定の行1つの出勤率を再判定する。まとめて付与する組の相手の行がある場合は、相手の行も判定して
 * 両方に判定を記録する(判定の内容は`PaidLeaveScheduleAssessmentPlanner`が決める)。
 */
class ReassessScheduleEntry implements Command
{
    public function __construct(
        public readonly string $scheduleEntryId,
    ) {}
}
