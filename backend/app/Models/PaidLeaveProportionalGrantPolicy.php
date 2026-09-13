<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * 比例付与(労働基準法第39条第3項、労働基準法施行規則第24条の3・別表第1)の
 * 法定付与日数マスタ(spec.md 論点4)。週所定労働日数区分
 * (`weekly_scheduled_days_category`: '4'週4日/'3'週3日/'2'週2日/'1'週1日)×
 * `continuous_service_months`で管理する。`version`単位で世代管理する。
 */
#[Fillable(['version', 'weekly_scheduled_days_category', 'continuous_service_months', 'grant_days', 'effective_from', 'is_active'])]
class PaidLeaveProportionalGrantPolicy extends Model
{
    public const CATEGORY_WEEKLY_4_DAYS = '4';

    public const CATEGORY_WEEKLY_3_DAYS = '3';

    public const CATEGORY_WEEKLY_2_DAYS = '2';

    public const CATEGORY_WEEKLY_1_DAY = '1';

    protected function casts(): array
    {
        return [
            'grant_days' => 'float',
            'effective_from' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /**
     * 指定した週所定労働日数区分・継続勤務月数に対する法定付与日数を返す
     * (`continuous_service_months`が最大一致するステップを採用)。
     */
    public static function grantDaysFor(string $weeklyScheduledDaysCategory, int $continuousServiceMonths, string $version): ?float
    {
        $policy = static::query()
            ->where('version', $version)
            ->where('weekly_scheduled_days_category', $weeklyScheduledDaysCategory)
            ->where('is_active', true)
            ->where('continuous_service_months', '<=', $continuousServiceMonths)
            ->orderByDesc('continuous_service_months')
            ->first();

        return $policy?->grant_days;
    }

    public static function latestVersion(): ?string
    {
        return static::query()
            ->where('is_active', true)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->value('version');
    }
}
