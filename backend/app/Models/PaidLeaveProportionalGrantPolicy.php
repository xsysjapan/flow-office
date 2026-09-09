<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 比例付与の法定日数表(労働基準法39条3項、週所定労働日数区分×継続勤務年数)。
 * spec.md 論点4。version管理。
 */
class PaidLeaveProportionalGrantPolicy extends Model
{
    protected $fillable = ['version', 'weekly_scheduled_days', 'continuous_service_months', 'grant_days'];

    /**
     * 現在有効な版番号(最大version)。
     */
    public static function currentVersion(): int
    {
        return (int) (static::query()->max('version') ?? 1);
    }

    /**
     * 指定した週所定労働日数・継続勤務月数に対応する付与日数(現在有効な版、
     * 週所定労働日数は完全一致、継続勤務月数は最大一致のステップを採用)。
     */
    public static function grantDaysFor(int $weeklyScheduledDays, int $continuousServiceMonths, ?int $version = null): ?float
    {
        $version ??= static::currentVersion();

        $days = static::query()
            ->where('version', $version)
            ->where('weekly_scheduled_days', $weeklyScheduledDays)
            ->where('continuous_service_months', '<=', $continuousServiceMonths)
            ->orderByDesc('continuous_service_months')
            ->value('grant_days');

        return $days === null ? null : (float) $days;
    }
}
