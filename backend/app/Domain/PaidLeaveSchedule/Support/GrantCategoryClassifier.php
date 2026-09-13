<?php

namespace App\Domain\PaidLeaveSchedule\Support;

/**
 * 通常付与/比例付与/シフト勤務の法定区分判定(依頼書§34-35)。ステートレス・
 * 副作用なし。`WorkStyle`の`weekly_scheduled_days`/`annual_scheduled_days`は
 * Phase B以降で追加される予定の列であり、本クラスは`$workStyle->weekly_scheduled_days ?? null`
 * のように防御的に読む(未追加の間はモデルの動的プロパティとしてnullが返る)。
 *
 * 判定ルール:
 * - `weekly_scheduled_days >= 5` OR `prescribed_weekly_minutes >= 1800`(週30時間) OR
 *   `annual_scheduled_days >= 217` → 通常付与。
 * - 上記に該当せず `is_shift_based = true` → シフト勤務区分。
 * - 上記いずれにも該当せず、かつ通常付与判定に必要な列(週所定労働日数・年間所定労働日数)が
 *   両方とも未入力 → 判定不能のためNeedsReview。
 * - それ以外 → 比例付与。
 */
class GrantCategoryClassifier
{
    public const CATEGORY_NORMAL = 'normal';

    public const CATEGORY_SHIFT = 'shift';

    public const CATEGORY_PROPORTIONAL = 'proportional';

    public const CATEGORY_NEEDS_REVIEW = 'needs_review';

    /**
     * @param  object{weekly_scheduled_days?: ?float, annual_scheduled_days?: ?float, prescribed_weekly_minutes?: ?int, is_shift_based?: ?bool}  $workStyle
     */
    public function classify(object $workStyle): string
    {
        $weeklyScheduledDays = $workStyle->weekly_scheduled_days ?? null;
        $annualScheduledDays = $workStyle->annual_scheduled_days ?? null;
        $prescribedWeeklyMinutes = $workStyle->prescribed_weekly_minutes ?? null;
        $isShiftBased = (bool) ($workStyle->is_shift_based ?? false);

        if (($weeklyScheduledDays !== null && $weeklyScheduledDays >= 5)
            || ($prescribedWeeklyMinutes !== null && $prescribedWeeklyMinutes >= 1800)
            || ($annualScheduledDays !== null && $annualScheduledDays >= 217)) {
            return self::CATEGORY_NORMAL;
        }

        if ($isShiftBased) {
            return self::CATEGORY_SHIFT;
        }

        if ($weeklyScheduledDays === null && $annualScheduledDays === null) {
            // 通常付与に該当しないと確定するための列(週所定労働日数・年間所定労働日数)が
            // どちらも未入力のため、比例付与と機械的に断定できない(依頼書§31の精神を
            // 通常/比例判定にも適用する)。
            return self::CATEGORY_NEEDS_REVIEW;
        }

        return self::CATEGORY_PROPORTIONAL;
    }
}
