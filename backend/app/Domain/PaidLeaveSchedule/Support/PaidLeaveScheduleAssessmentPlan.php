<?php

namespace App\Domain\PaidLeaveSchedule\Support;

/**
 * `PaidLeaveScheduleAssessmentPlanner::plan()`の結果。`RunAttendanceRateAssessment`にそのまま渡せる値を持つ。
 * `denominatorDays`/`attendanceDays`にはみなし日数を含めない(記録から出勤率を計算し直せる)。
 * 出勤率は(出勤日数 + みなし日数) ÷ (所定労働日数 + みなし日数)。
 */
final class PaidLeaveScheduleAssessmentPlan
{
    public function __construct(
        public readonly string $periodStart,
        public readonly string $periodEnd,
        public readonly int $denominatorDays,
        public readonly int $attendanceDays,
        public readonly int $excludedDays,
        public readonly int $deemedAttendanceDays,
        public readonly ?float $attendanceRate,
        public readonly string $automaticResult,
        public readonly ?string $needsReviewReason = null,
    ) {}
}
