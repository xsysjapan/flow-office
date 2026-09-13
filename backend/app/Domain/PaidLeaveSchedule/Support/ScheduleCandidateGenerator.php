<?php

namespace App\Domain\PaidLeaveSchedule\Support;

use App\Models\PaidLeaveGrantPolicy;
use App\Models\PaidLeaveGrantRule;
use App\Models\PaidLeaveProportionalGrantPolicy;
use App\Models\User;
use App\Models\UserWorkStyleMonthlyAssignment;
use App\Models\WorkStyle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Phase C: `paid-leave:roll-schedules`・Reactorから呼ばれる、対象社員の
 * Scheduleエントリ候補(scheduledOn・区分・付与候補日数)をステートレスに算出するサービス。
 *
 * Phase Aの設計方針(EnsureFutureScheduleGenerated/RecalculateFutureScheduleは
 * 算出済みの候補値を受け取るのみで、自らProjection/Eloquentへアクセスしない)を受けて、
 * 実際の算出(hire_dateからの記念日計算・区分判定・`resolveGrantDays`優先順位)は
 * このクラスがCommandHandler(artisanコマンド)側で行う。
 *
 * `resolveGrantDays`優先順位(spec.md 論点5/16): 対象社員の`work_style_id`に一致する
 * `paid_leave_grant_rules`が`is_active=true`で存在すればそちらのsteps定義を優先し、
 * 無ければ`GrantCategoryClassifier`の区分に対応する法定Policyを適用する。
 *
 * 判定に使う`WorkStyle`は「現在時点で有効な`user_work_style_monthly_assignments`」を
 * 採用する(将来の月ごとの働き方切替までは追跡しない。切替があった場合は
 * `WorkStyleUpdated`/`UserWorkStyleAssignedForMonth`をトリガーにReactorが
 * `RecalculateFutureSchedule`で再計算する運用を前提にする)。
 */
class ScheduleCandidateGenerator
{
    public function __construct(private readonly GrantCategoryClassifier $classifier) {}

    /**
     * @return array<int, array{entryId: string, scheduledOn: string, category: string, candidateGrantDays: float}>
     */
    public function candidatesFor(User $user, Carbon $from, Carbon $to): array
    {
        if ($user->hire_date === null) {
            return [];
        }

        $workStyle = $this->currentWorkStyleFor($user);
        $rule = $workStyle !== null
            ? PaidLeaveGrantRule::query()
                ->where('work_style_id', $workStyle->id)
                ->where('is_active', true)
                ->with('steps')
                ->first()
            : null;

        $category = $workStyle !== null
            ? $this->classifier->classify($workStyle)
            : GrantCategoryClassifier::CATEGORY_NEEDS_REVIEW;

        $firstGrantAfterMonths = $rule->first_grant_after_months ?? 6;
        $grantCycleMonths = max(1, $rule->grant_cycle_months ?? 12);

        $candidates = [];
        $months = $firstGrantAfterMonths;

        // 安全弁: hire_dateが極端に古い/未来指定でも無限ループしないよう上限を設ける。
        for ($i = 0; $i < 600; $i++, $months += $grantCycleMonths) {
            $scheduledOn = $user->hire_date->copy()->addMonths($months);

            if ($scheduledOn->gt($to)) {
                break;
            }

            if ($scheduledOn->gte($from)) {
                $candidates[] = [
                    'entryId' => (string) Str::uuid(),
                    'scheduledOn' => $scheduledOn->toDateString(),
                    'category' => $category,
                    'candidateGrantDays' => $this->resolveGrantDays($rule, $category, $workStyle, $months),
                ];
            }
        }

        return $candidates;
    }

    private function currentWorkStyleFor(User $user): ?WorkStyle
    {
        $currentYearMonth = Carbon::today()->format('Y-m');

        $assignment = UserWorkStyleMonthlyAssignment::query()
            ->where('user_id', $user->id)
            ->where('year_month', '<=', $currentYearMonth)
            ->orderByDesc('year_month')
            ->first();

        return $assignment?->workStyle;
    }

    private function resolveGrantDays(?PaidLeaveGrantRule $rule, string $category, ?WorkStyle $workStyle, int $continuousServiceMonths): float
    {
        if ($rule !== null) {
            $step = $rule->steps
                ->filter(fn ($step) => $step->continuous_service_months <= $continuousServiceMonths)
                ->sortByDesc('continuous_service_months')
                ->first();

            if ($step !== null) {
                return (float) $step->grant_days;
            }
        }

        return match ($category) {
            GrantCategoryClassifier::CATEGORY_NORMAL => PaidLeaveGrantPolicy::grantDaysFor(
                $continuousServiceMonths,
                PaidLeaveGrantPolicy::latestVersion() ?? 'v1',
            ) ?? 0.0,
            GrantCategoryClassifier::CATEGORY_PROPORTIONAL => PaidLeaveProportionalGrantPolicy::grantDaysFor(
                $this->weeklyScheduledDaysCategoryFor($workStyle),
                $continuousServiceMonths,
                PaidLeaveProportionalGrantPolicy::latestVersion() ?? 'v1',
            ) ?? 0.0,
            GrantCategoryClassifier::CATEGORY_SHIFT => $this->shiftGrantDaysEstimate($workStyle, $continuousServiceMonths),
            default => 0.0, // NeedsReview: 候補日数は未確定。管理者確認前提(依頼書§31の精神)。
        };
    }

    /**
     * 比例付与区分('4'/'3'/'2'/'1')への変換。`weekly_scheduled_days`が3〜4の間の
     * 端数等は切り捨てず「以上」で判定し、範囲外は1〜4へクランプする
     * (未入力の場合はGrantCategoryClassifierが既にNeedsReview/通常判定に倒しているため
     * ここには来ない想定だが、防御的に'4'にフォールバックする)。
     */
    private function weeklyScheduledDaysCategoryFor(?WorkStyle $workStyle): string
    {
        $days = $workStyle?->weekly_scheduled_days;

        if ($days === null) {
            return PaidLeaveProportionalGrantPolicy::CATEGORY_WEEKLY_4_DAYS;
        }

        $clamped = (int) max(1, min(4, round($days)));

        return (string) $clamped;
    }

    /**
     * シフト勤務区分(依頼書§35)の候補日数。本来は「初回6ヶ月は実労働日数×2、
     * 1.5年以降は前年実労働日数」という実績ベースの算出だが、Schedule事前生成の時点では
     * 未来の実績が存在しないため、`WorkStyle.agreed_scheduled_days_per_year`
     * (合意された目安勤務日数)を週相当日数に換算し、比例付与表を目安として適用する
     * (実際の確定値は出勤率Assessment・管理者Overrideで補正する運用を前提にした
     * 暫定近似値。Phase C実装時の判断)。目安が未設定の場合は0とし、管理画面の
     * NeedsReview相当の確認対象として扱う。
     */
    private function shiftGrantDaysEstimate(?WorkStyle $workStyle, int $continuousServiceMonths): float
    {
        $agreedDaysPerYear = $workStyle?->agreed_scheduled_days_per_year;

        if ($agreedDaysPerYear === null) {
            return 0.0;
        }

        $weeklyEquivalent = (int) max(1, min(4, round($agreedDaysPerYear / 52)));

        return PaidLeaveProportionalGrantPolicy::grantDaysFor(
            (string) $weeklyEquivalent,
            $continuousServiceMonths,
            PaidLeaveProportionalGrantPolicy::latestVersion() ?? 'v1',
        ) ?? 0.0;
    }
}
