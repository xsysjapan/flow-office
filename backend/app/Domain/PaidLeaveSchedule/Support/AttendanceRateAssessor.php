<?php

namespace App\Domain\PaidLeaveSchedule\Support;

use App\Domain\PaidLeaveSchedule\Aggregates\PaidLeaveScheduleAggregate;
use App\Models\AttendanceDay;
use App\Models\AttendanceDayStatus;
use App\Models\EmployeeCalendarEntry;
use Illuminate\Support\Carbon;

/**
 * 出勤率Assessmentの分母/分子算出をステートレスに行う。既存
 * `GrantScheduledPaidLeaveHandler::meetsAttendanceRate()`と同一の分母/分子定義
 * (直近期間の`EmployeeCalendarEntry.is_working_day`を分母、`AttendanceDay.status='clocked_out'
 * OR work_type LIKE 'paid_leave_%'`を分子)を踏襲しつつ、以下の場合は自動80%未満判定にせず
 * `NeedsReview`として返す(依頼書§31/§32):
 * - 分母日数が0件(判定材料が無い)。
 * - Assessment期間が対象社員の`usage_start_date`より前を含み、かつ旧システムからの
 *   移行データが無い場合。
 *
 * Projection/Eloquentへの読み取りアクセスはこのクラスに閉じ込め、Aggregate自体は
 * 判定結果を記録するだけに留める(docs/03-architecture.md、spec.md 論点9参照)。
 */
class AttendanceRateAssessor
{
    public const POLICY_VERSION = 'v1';

    public function assess(
        string $userId,
        Carbon $periodStart,
        Carbon $periodEnd,
        float $minAttendanceRate,
        ?Carbon $usageStartDate = null,
        bool $hasMigratedLegacyData = false,
    ): AttendanceRateAssessmentResult {
        if ($usageStartDate !== null && $periodStart->lt($usageStartDate) && ! $hasMigratedLegacyData) {
            return new AttendanceRateAssessmentResult(
                denominatorDays: 0,
                attendanceDays: 0,
                excludedDays: 0,
                attendanceRate: null,
                automaticResult: PaidLeaveScheduleAggregate::STATUS_NEEDS_REVIEW,
                needsReviewReason: 'usage_start_date_boundary',
            );
        }

        $scheduledDates = EmployeeCalendarEntry::query()
            ->where('user_id', $userId)
            ->where('is_working_day', true)
            ->whereDate('work_date', '>=', $periodStart->toDateString())
            ->whereDate('work_date', '<=', $periodEnd->toDateString())
            ->pluck('work_date')
            ->map(fn ($date) => $date->toDateString());

        if ($scheduledDates->isEmpty()) {
            return new AttendanceRateAssessmentResult(
                denominatorDays: 0,
                attendanceDays: 0,
                excludedDays: 0,
                attendanceRate: null,
                automaticResult: PaidLeaveScheduleAggregate::STATUS_NEEDS_REVIEW,
                needsReviewReason: 'no_scheduled_working_days',
            );
        }

        $attendedDates = AttendanceDay::query()
            ->where('user_id', $userId)
            ->whereDate('work_date', '>=', $periodStart->toDateString())
            ->whereDate('work_date', '<=', $periodEnd->toDateString())
            ->where(function ($query) {
                $query->where('status', AttendanceDayStatus::CLOCKED_OUT)
                    ->orWhere('work_type', 'like', 'paid_leave_%');
            })
            ->pluck('work_date')
            ->map(fn ($date) => $date->toDateString());

        $denominatorDays = $scheduledDates->count();
        $attendanceDays = $scheduledDates->intersect($attendedDates)->count();
        $rate = ($attendanceDays / $denominatorDays) * 100;

        $automaticResult = $rate >= $minAttendanceRate
            ? PaidLeaveScheduleAggregate::STATUS_ELIGIBLE
            : PaidLeaveScheduleAggregate::STATUS_NOT_ELIGIBLE;

        return new AttendanceRateAssessmentResult(
            denominatorDays: $denominatorDays,
            attendanceDays: $attendanceDays,
            excludedDays: 0,
            attendanceRate: $rate,
            automaticResult: $automaticResult,
        );
    }
}
