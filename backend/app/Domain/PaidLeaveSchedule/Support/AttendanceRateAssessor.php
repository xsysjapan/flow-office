<?php

namespace App\Domain\PaidLeaveSchedule\Support;

use App\Domain\PaidLeaveSchedule\Aggregates\PaidLeaveScheduleAggregate;
use App\Domain\Leave\Support\LeaveAttendanceRateJudgement;
use App\Models\LeaveAttendanceRateDay;
use Illuminate\Support\Carbon;

/**
 * 出勤率Assessmentの分母/分子算出をステートレスに行う。分母/分子の定義は従来の
 * `GrantScheduledPaidLeaveHandler::meetsAttendanceRate()`と同じ(仕様確定事項F・論点9)。
 * 入力は有給の出勤率ビュー(`leave_attendance_rate_days`)だけで、勤怠日・カレンダーのテーブルは読まない。
 * - 分母: 対象期間の`is_working_day`の日(カレンダーの割当イベント由来)。
 * - 分子: 退勤済み ∪ 全休の休暇(3種) ∪ 有給の半休・時間休(`LeaveAttendanceRateJudgement`)。
 * 以下の場合は自動80%未満判定にせず
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

        $scheduledDays = LeaveAttendanceRateDay::query()
            ->where('user_id', $userId)
            ->where('is_working_day', true)
            ->whereDate('work_date', '>=', $periodStart->toDateString())
            ->whereDate('work_date', '<=', $periodEnd->toDateString())
            ->get();

        if ($scheduledDays->isEmpty()) {
            return new AttendanceRateAssessmentResult(
                denominatorDays: 0,
                attendanceDays: 0,
                excludedDays: 0,
                attendanceRate: null,
                automaticResult: PaidLeaveScheduleAggregate::STATUS_NEEDS_REVIEW,
                needsReviewReason: 'no_scheduled_working_days',
            );
        }

        $denominatorDays = $scheduledDays->count();
        $attendanceDays = $scheduledDays->filter(fn (LeaveAttendanceRateDay $day) => LeaveAttendanceRateJudgement::countsAsAttended(
            $day->attended,
            $day->full_leave_kinds ?? [],
            $day->partial_leave_kinds ?? [],
            [LeaveAttendanceRateJudgement::KIND_PAID],
        ))->count();
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
