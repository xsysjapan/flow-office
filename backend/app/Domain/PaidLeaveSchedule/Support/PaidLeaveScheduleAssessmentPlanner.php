<?php

namespace App\Domain\PaidLeaveSchedule\Support;

use App\Domain\PaidLeaveSchedule\Aggregates\PaidLeaveScheduleAggregate;
use App\Models\PaidLeaveGrant;
use App\Models\PaidLeaveScheduleEntry;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 付与予定1行の出勤率判定の内容(期間・みなし出勤・閾値)を決め、`AttendanceRateAssessor`で判定する
 * (docs/changesets/20261021-paid-leave-fiscal-mass-grant-and-auto-assessment/spec.md 論点5・10)。
 * 再判定API(`PaidLeaveScheduleController::reassess`)と自動判定(`paid-leave:assess-schedules`)の両方から使う。
 *
 * - 閾値・判定周期・初回の月数: 社員の現在の勤務形態に合うルール(`PaidLeaveGrantRuleResolver`。勤務形態のルール→全社ルール)。
 *   ルールが無ければ80%・12か月・6か月。
 * - 行の位置づけ(同じ社員の付与予定のうち、取消を除く):
 *   初回 = 付与予定日が「入社日 + 初回の月数」以前で、それより前の行が無い行。前回 = 付与予定日が前の行のうち最も遅い行。
 * - 期間: 終わりは付与予定日の前日。始まりは、初回なら入社日、2回目以降なら前回の付与予定日。
 *   初回でも前回も無い行は「付与予定日 − 判定周期」。
 * - 短縮期間(みなし出勤): 本来の基準日は、初回なら「入社日 + 初回の月数」、2回目以降なら「前回の付与予定日 + 判定周期」。
 *   付与予定日が本来の基準日より前なら、付与予定日から基準日の前日までを短縮期間とし、所定労働日数を暦日で按分して
 *   みなし日数を推計する(round(実期間の所定労働日数 × 短縮期間の暦日数 ÷ 実期間の暦日数))。みなし日数は全出勤とみなす。
 *   平6.1.4基発1号の考え方による。社労士の確認が前提。
 * - まとめて付与する2件の2件目(同じ付与予定日で名目が「初回の月数 + 12」、同じ日に名目が初回の月数の行がある): 期間は
 *   すべて前倒しの短縮期間に当たるため、1件目の判定とは共有せず、全出勤とみなして付与対象にする。
 *   利用開始日の規則による要確認は残す。
 *
 * 原則15の既存の例外: 移行データ(`source=carried_over`の付与)の有無は、有給口座の読み取りビュー(`PaidLeaveGrant`)を
 * 直接読んで確かめる(従来の`reassess`と同じ。付与予定の文脈に移行データの写しを持たない)。
 */
class PaidLeaveScheduleAssessmentPlanner
{
    private const DEFAULT_MIN_ATTENDANCE_RATE = 80.0;

    private const DEFAULT_GRANT_CYCLE_MONTHS = 12;

    private const DEFAULT_FIRST_GRANT_AFTER_MONTHS = 6;

    public function __construct(
        private readonly AttendanceRateAssessor $assessor,
        private readonly PaidLeaveGrantRuleResolver $ruleResolver,
        private readonly ScheduleCandidateGenerator $generator,
    ) {}

    public function plan(PaidLeaveScheduleEntry $entry, User $user): PaidLeaveScheduleAssessmentPlan
    {
        $rule = $this->ruleResolver->resolveFor($this->generator->currentWorkStyleFor($user));

        $minRate = (float) ($rule->min_attendance_rate ?? self::DEFAULT_MIN_ATTENDANCE_RATE);
        $cycleMonths = max(1, (int) ($rule->grant_cycle_months ?? self::DEFAULT_GRANT_CYCLE_MONTHS));
        $firstAfterMonths = (int) ($rule->first_grant_after_months ?? self::DEFAULT_FIRST_GRANT_AFTER_MONTHS);

        $scheduledOn = $entry->scheduled_on->copy()->startOfDay();
        $periodEnd = $scheduledOn->copy()->subDay();
        $hireDate = $user->hire_date?->copy()->startOfDay();
        $usageStartDate = $user->usage_start_date?->copy()->startOfDay();
        $hasMigratedLegacyData = $this->hasMigratedLegacyData($user->id);

        $otherEntries = PaidLeaveScheduleEntry::query()
            ->where('user_id', $user->id)
            ->where('status', '!=', PaidLeaveScheduleEntry::STATUS_CANCELLED)
            ->where('id', '!=', $entry->id)
            ->get();

        if ($this->isBundledSecondEntry($entry, $otherEntries, $firstAfterMonths)) {
            return $this->planBundledSecondEntry($scheduledOn, $hireDate, $firstAfterMonths, $cycleMonths, $usageStartDate, $hasMigratedLegacyData);
        }

        $previous = $otherEntries
            ->filter(fn (PaidLeaveScheduleEntry $other) => $other->scheduled_on->copy()->startOfDay()->lt($scheduledOn))
            ->sortByDesc(fn (PaidLeaveScheduleEntry $other) => $other->scheduled_on->toDateString())
            ->first();

        $sixMonthMark = $hireDate?->copy()->addMonthsNoOverflow($firstAfterMonths);
        $isFirst = $sixMonthMark !== null && $previous === null && $scheduledOn->lte($sixMonthMark);

        $previousOn = $previous?->scheduled_on->copy()->startOfDay();

        if ($isFirst) {
            $periodStart = $hireDate->copy();
            $naturalBase = $sixMonthMark;
        } elseif ($previousOn !== null) {
            $periodStart = $previousOn->copy();
            $naturalBase = $previousOn->copy()->addMonthsNoOverflow($cycleMonths);
        } else {
            $periodStart = $scheduledOn->copy()->subMonthsNoOverflow($cycleMonths);
            $naturalBase = null;
        }

        $result = $this->assessor->assess(
            userId: $user->id,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            minAttendanceRate: $minRate,
            usageStartDate: $usageStartDate,
            hasMigratedLegacyData: $hasMigratedLegacyData,
        );

        $deemed = 0;
        if ($naturalBase !== null && $scheduledOn->lt($naturalBase) && $result->denominatorDays > 0) {
            $actualCalendarDays = $periodStart->diffInDays($periodEnd) + 1;
            $shortCalendarDays = $scheduledOn->diffInDays($naturalBase->copy()->subDay()) + 1;

            if ($actualCalendarDays > 0) {
                $deemed = (int) round($result->denominatorDays * $shortCalendarDays / $actualCalendarDays);
            }
        }

        if ($deemed === 0) {
            return new PaidLeaveScheduleAssessmentPlan(
                periodStart: $periodStart->toDateString(),
                periodEnd: $periodEnd->toDateString(),
                denominatorDays: $result->denominatorDays,
                attendanceDays: $result->attendanceDays,
                excludedDays: $result->excludedDays,
                deemedAttendanceDays: 0,
                attendanceRate: $result->attendanceRate,
                automaticResult: $result->automaticResult,
                needsReviewReason: $result->needsReviewReason,
            );
        }

        $rate = (($result->attendanceDays + $deemed) / ($result->denominatorDays + $deemed)) * 100;

        return new PaidLeaveScheduleAssessmentPlan(
            periodStart: $periodStart->toDateString(),
            periodEnd: $periodEnd->toDateString(),
            denominatorDays: $result->denominatorDays,
            attendanceDays: $result->attendanceDays,
            excludedDays: $result->excludedDays,
            deemedAttendanceDays: $deemed,
            attendanceRate: $rate,
            automaticResult: $rate >= $minRate
                ? PaidLeaveScheduleAggregate::STATUS_ELIGIBLE
                : PaidLeaveScheduleAggregate::STATUS_NOT_ELIGIBLE,
        );
    }

    /**
     * まとめて付与する2件の2件目か(名目が「初回の月数 + 12」で、同じ付与予定日に名目が初回の月数の未取消の行がある)。
     *
     * @param  Collection<int, PaidLeaveScheduleEntry>  $otherEntries
     */
    private function isBundledSecondEntry(PaidLeaveScheduleEntry $entry, $otherEntries, int $firstAfterMonths): bool
    {
        if ($entry->nominal_service_months === null || (int) $entry->nominal_service_months !== $firstAfterMonths + 12) {
            return false;
        }

        $scheduledOn = $entry->scheduled_on->toDateString();

        return $otherEntries->contains(fn (PaidLeaveScheduleEntry $other) => $other->scheduled_on->toDateString() === $scheduledOn
            && $other->nominal_service_months !== null
            && (int) $other->nominal_service_months === $firstAfterMonths);
    }

    /**
     * まとめた2件目: 期間は付与予定日から、本来の基準日(入社日 + 初回の月数 + 12か月。入社日が無ければ付与予定日 + 判定周期)の
     * 前日まで。すべて前倒しの短縮期間なので、実績・推計の日数は持たず(0)、全出勤とみなして付与対象にする(出勤率100%)。
     */
    private function planBundledSecondEntry(Carbon $scheduledOn, ?Carbon $hireDate, int $firstAfterMonths, int $cycleMonths, ?Carbon $usageStartDate, bool $hasMigratedLegacyData): PaidLeaveScheduleAssessmentPlan
    {
        $naturalBase = $hireDate !== null
            ? $hireDate->copy()->addMonthsNoOverflow($firstAfterMonths + 12)
            : $scheduledOn->copy()->addMonthsNoOverflow($cycleMonths);

        $periodEnd = $naturalBase->copy()->subDay();
        if ($periodEnd->lt($scheduledOn)) {
            $periodEnd = $scheduledOn->copy();
        }

        $needsReview = AttendanceRateAssessor::needsReviewForUsageStart($scheduledOn, $usageStartDate, $hasMigratedLegacyData);

        return new PaidLeaveScheduleAssessmentPlan(
            periodStart: $scheduledOn->toDateString(),
            periodEnd: $periodEnd->toDateString(),
            denominatorDays: 0,
            attendanceDays: 0,
            excludedDays: 0,
            deemedAttendanceDays: 0,
            attendanceRate: $needsReview ? null : 100.0,
            automaticResult: $needsReview
                ? PaidLeaveScheduleAggregate::STATUS_NEEDS_REVIEW
                : PaidLeaveScheduleAggregate::STATUS_ELIGIBLE,
            needsReviewReason: $needsReview ? 'usage_start_date_boundary' : null,
        );
    }

    private function hasMigratedLegacyData(string $userId): bool
    {
        return PaidLeaveGrant::query()
            ->where('user_id', $userId)
            ->where('source', 'carried_over')
            ->exists();
    }
}
