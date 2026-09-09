<?php

namespace App\Domain\PaidLeaveSchedule\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * `AttendanceRateAssessor`による最新の出勤率判定結果を記録する。既に`manualOverride`が
 * 存在する場合でもこのイベント自体は発行されるが、Aggregateの導出ステータスは
 * `finalResult`(Override結果)を優先し自動判定に引きずられない(spec.md論点7)。
 */
class PaidLeaveScheduleAssessmentRecorded extends ShouldBeStored
{
    public function __construct(
        public readonly string $userId,
        public readonly string $entryId,
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
