<?php

namespace App\Domain\PaidLeaveSchedule\Support;

use App\Models\PaidLeaveGrantRule;
use App\Models\WorkStyle;

/**
 * 社員の勤務形態に合う有効な付与ルールを1件選ぶ。勤務形態のルール→全社ルール(`work_style_id`がnull)の順、
 * 同じ種類が複数あれば`created_at`→`id`の古い方(毎日の再計算で選ばれるルールが日によって変わり、置き換えが
 * 出続けるのを防ぐ。docs/changesets/20261021-paid-leave-schedule-recalc-on-assignment-change/spec.md 論点4、
 * docs/changesets/20261021-paid-leave-fiscal-mass-grant-and-auto-assessment/spec.md 論点4)。
 * 付与予定の日付・日数(`ScheduleCandidateGenerator`)と、出勤率の判定で共通に使う。
 */
class PaidLeaveGrantRuleResolver
{
    public function resolveFor(?WorkStyle $workStyle): ?PaidLeaveGrantRule
    {
        if ($workStyle !== null) {
            $specific = $this->firstActive($workStyle->id);

            if ($specific !== null) {
                return $specific;
            }
        }

        return $this->firstActive(null);
    }

    private function firstActive(?string $workStyleId): ?PaidLeaveGrantRule
    {
        return PaidLeaveGrantRule::query()
            ->when(
                $workStyleId === null,
                fn ($query) => $query->whereNull('work_style_id'),
                fn ($query) => $query->where('work_style_id', $workStyleId),
            )
            ->where('is_active', true)
            ->orderBy('created_at')
            ->orderBy('id')
            ->with('steps')
            ->first();
    }
}
