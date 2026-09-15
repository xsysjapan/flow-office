<?php

namespace App\Domain\PaidLeaveSchedule\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 指定Scheduleエントリの出勤率Assessmentを記録する。分母/分子等の算出自体は
 * `App\Domain\PaidLeaveSchedule\Support\AttendanceRateAssessor`が行い、この時点で
 * 確定した結果をCommandへ渡す(Aggregateは判定結果の記録・状態遷移のみを担う)。
 */
class RunAttendanceRateAssessment implements Command
{
    public function __construct(
        public readonly string $userId,
        public readonly string $scheduleEntryId,
        public readonly string $assessmentId,
        public readonly string $periodStart,
        public readonly string $periodEnd,
        public readonly int $denominatorDays,
        public readonly int $attendanceDays,
        public readonly int $excludedDays,
        public readonly ?float $attendanceRate,
        public readonly string $policyVersion,
        public readonly string $automaticResult,
    ) {}
}
