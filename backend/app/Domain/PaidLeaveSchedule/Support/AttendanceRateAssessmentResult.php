<?php

namespace App\Domain\PaidLeaveSchedule\Support;

/**
 * `AttendanceRateAssessor::assess()`の結果。`automaticResult`は
 * `PaidLeaveScheduleAggregate::STATUS_ELIGIBLE`/`STATUS_NOT_ELIGIBLE`/`STATUS_NEEDS_REVIEW`
 * のいずれかの文字列を持つ。
 */
final class AttendanceRateAssessmentResult
{
    public function __construct(
        public readonly int $denominatorDays,
        public readonly int $attendanceDays,
        public readonly int $excludedDays,
        public readonly ?float $attendanceRate,
        public readonly string $automaticResult,
        public readonly ?string $needsReviewReason = null,
    ) {}
}
