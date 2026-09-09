<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 通常付与の法定日数表(労働基準法39条)。docs/changesets/
 * 20260906-paid-leave-schedule-assessment/spec.md 論点4。version管理。
 */
class PaidLeaveGrantPolicy extends Model
{
    protected $fillable = ['version', 'continuous_service_months', 'grant_days'];

    /**
     * 現在有効な版番号(最大version)。
     */
    public static function currentVersion(): int
    {
        return (int) (static::query()->max('version') ?? 1);
    }

    /**
     * 指定した継続勤務月数に対応する付与日数(現在有効な版、最大一致のステップを採用)。
     */
    public static function grantDaysFor(int $continuousServiceMonths, ?int $version = null): ?float
    {
        $version ??= static::currentVersion();

        $days = static::query()
            ->where('version', $version)
            ->where('continuous_service_months', '<=', $continuousServiceMonths)
            ->orderByDesc('continuous_service_months')
            ->value('grant_days');

        return $days === null ? null : (float) $days;
    }
}
