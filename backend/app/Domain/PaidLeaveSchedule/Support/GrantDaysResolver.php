<?php

namespace App\Domain\PaidLeaveSchedule\Support;

use App\Models\PaidLeaveGrantPolicy;
use App\Models\PaidLeaveGrantRule;
use App\Models\PaidLeaveProportionalGrantPolicy;
use App\Models\WorkStyle;

/**
 * Schedule候補付与日数の決定(spec.md論点5)。「対象社員に一致する`work_style_id`の
 * `paid_leave_grant_rules`が`is_active=true`で存在すればそちらを優先、無ければ
 * `GrantCategoryClassifier`の区分に対応する法定Policy(`paid_leave_grant_policies`/
 * `paid_leave_proportional_grant_policies`)を適用する」という優先順位を1箇所に
 * まとめ、`EnsureFutureScheduleGeneratedHandler`/`RecalculateFutureScheduleHandler`の
 * 重複を解消する(Phase AではPolicyマスタが無くこの解決ができなかったため、Handler側に
 * 「無ければ0日」という暫定実装が重複していた)。
 */
class GrantDaysResolver
{
    /**
     * @param  PaidLeaveGrantRule|null  $rule  対象社員に適用可能な独自ルール(存在すれば優先)
     * @param  int  $continuousServiceMonths  継続勤務月数(候補付与日時点)
     * @param  string  $category  `GrantCategoryClassifier::classify()`の結果
     * @param  WorkStyle|null  $workStyle  比例付与区分判定に必要な週所定労働日数の参照元
     */
    public function resolve(
        ?PaidLeaveGrantRule $rule,
        int $continuousServiceMonths,
        string $category,
        ?WorkStyle $workStyle,
    ): float {
        if ($rule !== null) {
            $applicableStep = $rule->steps
                ->filter(fn ($step) => $step->continuous_service_months <= $continuousServiceMonths)
                ->sortByDesc('continuous_service_months')
                ->first();

            return (float) ($applicableStep?->grant_days ?? 0);
        }

        return match ($category) {
            GrantCategory::REGULAR => PaidLeaveGrantPolicy::grantDaysFor($continuousServiceMonths) ?? 0.0,
            GrantCategory::PROPORTIONAL => $this->resolveProportional($continuousServiceMonths, $workStyle),
            // シフト勤務区分(依頼書§35)の候補日数算出には実労働日数の実績参照が必要で、
            // 単純なマスタ参照では決まらない。Phase B時点ではPolicyマスタ(通常/比例のみ)を
            // 対象とするため、シフト区分は暫定的に0日(NeedsReview相当)のままとし、
            // 実装はPhase C(`paid-leave:roll-schedules`本体)以降に譲る。
            default => 0.0,
        };
    }

    private function resolveProportional(int $continuousServiceMonths, ?WorkStyle $workStyle): float
    {
        $weeklyDays = $workStyle?->weekly_scheduled_days;

        if ($weeklyDays === null) {
            return 0.0;
        }

        return PaidLeaveProportionalGrantPolicy::grantDaysFor($weeklyDays, $continuousServiceMonths) ?? 0.0;
    }
}
