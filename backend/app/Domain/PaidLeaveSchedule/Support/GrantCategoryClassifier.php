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

    /**
     * 比例付与の社員の、付与日数の表の区分(`proportional_{N}`。Nは週の所定労働日数4/3/2/1)。
     * 週所定日数(`weekly_scheduled_days`)が1以上5未満ならその整数部分(切り捨て)。週所定日数が無ければ
     * 年間所定日数(`annual_scheduled_days`)で決める(労基則24条の3: 169〜216→4、121〜168→3、73〜120→2、48〜72→1)。
     * 週0日(1未満)・年間48日未満・どちらも無い場合は決められないのでnull(要確認)。
     *
     * @param  object{weekly_scheduled_days?: ?float, annual_scheduled_days?: ?float}  $workStyle
     */
    public function proportionalGrantCategoryFor(object $workStyle): ?string
    {
        $weekly = $workStyle->weekly_scheduled_days ?? null;

        if ($weekly !== null) {
            $days = (int) floor((float) $weekly);

            return $days >= 1 ? 'proportional_'.min(4, $days) : null;
        }

        $annual = $workStyle->annual_scheduled_days ?? null;

        if ($annual === null) {
            return null;
        }

        return match (true) {
            $annual >= 169 => 'proportional_4',
            $annual >= 121 => 'proportional_3',
            $annual >= 73 => 'proportional_2',
            $annual >= 48 => 'proportional_1',
            default => null,
        };
    }
}
