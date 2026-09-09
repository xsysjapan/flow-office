<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 有給付与の時効(労働基準法115条、既定2年)。spec.md 論点4。version管理。
 */
class PaidLeaveGrantExpiryPolicy extends Model
{
    protected $table = 'paid_leave_grant_expiry_policy';

    protected $fillable = ['version', 'expiry_years'];

    /**
     * 現在有効な版の時効年数(最大version)。行が1件も無い場合は法定既定値2年。
     */
    public static function currentExpiryYears(): int
    {
        return (int) (static::query()->orderByDesc('version')->value('expiry_years') ?? 2);
    }
}
