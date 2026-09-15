<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * 通常付与(労働基準法第39条第1項・第2項)の法定付与日数マスタ
 * (docs/changesets/20260906-paid-leave-schedule-assessment/spec.md 論点4)。
 * `version`単位で世代管理する。法務判断が必要な値のためマスタ化し、ハードコードしない
 * (CLAUDE.md原則8)。
 */
#[Fillable(['version', 'continuous_service_months', 'grant_days', 'effective_from', 'is_active'])]
class PaidLeaveGrantPolicy extends Model
{
    protected function casts(): array
    {
        return [
            'grant_days' => 'float',
            'effective_from' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /**
     * 指定した継続勤務月数に対する法定付与日数を返す(`continuous_service_months`が
     * 最大一致するステップを採用する。現行`GrantScheduledPaidLeaveHandler::resolveGrantDays()`
     * と同じ考え方)。該当ステップが無ければnull。
     */
    public static function grantDaysFor(int $continuousServiceMonths, string $version): ?float
    {
        $policy = static::query()
            ->where('version', $version)
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
