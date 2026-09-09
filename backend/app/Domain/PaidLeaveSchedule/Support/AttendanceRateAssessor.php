<?php

namespace App\Domain\PaidLeaveSchedule\Support;

use App\Models\AttendanceDay;
use App\Models\AttendanceDayStatus;
use App\Models\EmployeeCalendarEntry;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * 出勤率Assessment(依頼書§31-32)。ステートレスサービス。現行
 * `App\Domain\PaidLeave\Handlers\GrantScheduledPaidLeaveHandler::meetsAttendanceRate()`と
 * 同一の分母/分子定義(直近期間の`EmployeeCalendarEntry.is_working_day`を分母、
 * `AttendanceDay.status='clocked_out' OR work_type LIKE 'paid_leave_%'`を分子)を踏襲しつつ、
 * 分母日数ゼロ、または期間が`usage_start_date`より前を含みかつ移行データが無い場合は
 * `NeedsReview`を返すよう一般化する(spec.md論点9)。
 *
 * `policyVersion`は現時点では固定値('v1')。実際のPolicyバージョン管理(法定Policy
 * マスタとの連動)はPhase Bで導入する。
 */
class AttendanceRateAssessor
{
    private const POLICY_VERSION = 'v1';

    private const DEFAULT_MIN_ATTENDANCE_RATE = 80.0;

    /**
     * @return array{periodStart: string, periodEnd: string, denominatorDays: int, attendanceDays: int, excludedDays: int, attendanceRate: ?float, policyVersion: string, automaticResult: string}
     */
    public function assess(User $user, Carbon $periodStart, Carbon $periodEnd): array
    {
        $scheduledDates = EmployeeCalendarEntry::query()
            ->where('user_id', $user->id)
            ->where('is_working_day', true)
            ->whereDate('work_date', '>=', $periodStart->toDateString())
            ->whereDate('work_date', '<=', $periodEnd->toDateString())
            ->pluck('work_date')
            ->map(fn ($date) => $date->toDateString());

        $denominatorDays = $scheduledDates->count();

        if ($denominatorDays === 0) {
            // 依頼書§31: 期間中の勤務予定日が1件も無い場合は判定不能。
            return $this->needsReviewResult($periodStart, $periodEnd, 0);
        }

        if ($user->usage_start_date !== null && $periodStart->lt(Carbon::parse($user->usage_start_date))) {
            $hasPreMigrationData = AttendanceDay::query()
                ->where('user_id', $user->id)
                ->whereDate('work_date', '>=', $periodStart->toDateString())
                ->whereDate('work_date', '<', Carbon::parse($user->usage_start_date)->toDateString())
                ->exists();

            if (! $hasPreMigrationData) {
                // 依頼書§32: usage_start_date跨ぎで、それ以前の勤怠データ(移行データ)が
                // 無い場合は機械的な80%未満判定にせず要確認とする。
                return $this->needsReviewResult($periodStart, $periodEnd, $denominatorDays);
            }
        }

        $attendedDates = AttendanceDay::query()
            ->where('user_id', $user->id)
            ->whereDate('work_date', '>=', $periodStart->toDateString())
            ->whereDate('work_date', '<=', $periodEnd->toDateString())
            ->where(function ($query) {
                $query->where('status', AttendanceDayStatus::CLOCKED_OUT)
                    ->orWhere('work_type', 'like', 'paid_leave_%');
            })
            ->pluck('work_date')
            ->map(fn ($date) => $date->toDateString());

        $attendanceDays = $scheduledDates->intersect($attendedDates)->count();

        // Phase A時点では休職等の除外日ロジックは未実装(常に0)。Phase B以降の拡張余地。
        $excludedDays = 0;

        $rate = ($attendanceDays / $denominatorDays) * 100;

        return [
            'periodStart' => $periodStart->toDateString(),
            'periodEnd' => $periodEnd->toDateString(),
            'denominatorDays' => $denominatorDays,
            'attendanceDays' => $attendanceDays,
            'excludedDays' => $excludedDays,
            'attendanceRate' => $rate,
            'policyVersion' => self::POLICY_VERSION,
            'automaticResult' => $rate >= self::DEFAULT_MIN_ATTENDANCE_RATE
                ? ScheduleEntryStatus::ELIGIBLE
                : ScheduleEntryStatus::NOT_ELIGIBLE,
        ];
    }

    /**
     * @return array{periodStart: string, periodEnd: string, denominatorDays: int, attendanceDays: int, excludedDays: int, attendanceRate: ?float, policyVersion: string, automaticResult: string}
     */
    private function needsReviewResult(Carbon $periodStart, Carbon $periodEnd, int $denominatorDays): array
    {
        return [
            'periodStart' => $periodStart->toDateString(),
            'periodEnd' => $periodEnd->toDateString(),
            'denominatorDays' => $denominatorDays,
            'attendanceDays' => 0,
            'excludedDays' => 0,
            'attendanceRate' => null,
            'policyVersion' => self::POLICY_VERSION,
            'automaticResult' => ScheduleEntryStatus::NEEDS_REVIEW,
        ];
    }
}
