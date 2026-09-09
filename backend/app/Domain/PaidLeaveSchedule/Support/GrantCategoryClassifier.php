<?php

namespace App\Domain\PaidLeaveSchedule\Support;

use App\Models\WorkStyle;

/**
 * 通常/比例/シフト勤務の法定区分判定(依頼書§34-35、spec.md論点3・4)。ステートレス。
 *
 * - `WorkStyle.is_shift_based = true` → シフト勤務区分。
 * - それ以外で `weekly_scheduled_days >= 5` または `prescribed_weekly_minutes >= 1800`
 *   (30時間) または `annual_scheduled_days >= 217` → 通常付与。
 * - 上記いずれの判定にも必要な列(is_shift_based以外の3列すべて)が未入力の場合は
 *   `NeedsReview`(依頼書§31の精神を通常/比例判定にも適用)。
 * - どちらでもない(所定労働日数等のデータはあるが基準未満) → 比例付与。
 */
class GrantCategoryClassifier
{
    private const REGULAR_WEEKLY_DAYS_THRESHOLD = 5;

    private const REGULAR_WEEKLY_MINUTES_THRESHOLD = 1800; // 30時間

    private const REGULAR_ANNUAL_DAYS_THRESHOLD = 217;

    public function classify(?WorkStyle $workStyle): string
    {
        if ($workStyle === null) {
            return GrantCategory::NEEDS_REVIEW;
        }

        if ($workStyle->is_shift_based) {
            return GrantCategory::SHIFT;
        }

        $weeklyDays = $workStyle->weekly_scheduled_days;
        $weeklyMinutes = $workStyle->prescribed_weekly_minutes;
        $annualDays = $workStyle->annual_scheduled_days;

        // `prescribed_weekly_minutes`は`work_styles`の必須列(既存)のため常に値を持つ。
        // 本判定にとって「未入力」判定の対象になるのは、今回追加した2つのnullable列
        // (weekly_scheduled_days/annual_scheduled_days)が両方とも未入力の場合のみ。
        if ($weeklyDays === null && $annualDays === null) {
            return GrantCategory::NEEDS_REVIEW;
        }

        if (
            ($weeklyDays !== null && $weeklyDays >= self::REGULAR_WEEKLY_DAYS_THRESHOLD)
            || ($weeklyMinutes !== null && $weeklyMinutes >= self::REGULAR_WEEKLY_MINUTES_THRESHOLD)
            || ($annualDays !== null && $annualDays >= self::REGULAR_ANNUAL_DAYS_THRESHOLD)
        ) {
            return GrantCategory::REGULAR;
        }

        return GrantCategory::PROPORTIONAL;
    }
}
