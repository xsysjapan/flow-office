<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * 有給休暇の時効(労働基準法第115条、既定2年)マスタ(spec.md 論点4)。
 * `version`単位で世代管理する。
 */
#[Fillable(['version', 'expiry_years', 'effective_from', 'is_active'])]
class PaidLeaveGrantExpiryPolicy extends Model
{
    public const DEFAULT_EXPIRY_YEARS = 2;

    /**
     * 単一マスタのため単数形テーブル名(`paid_leave_grant_expiry_policy`)を使う。
     */
    protected $table = 'paid_leave_grant_expiry_policy';

    protected function casts(): array
    {
        return [
            'expiry_years' => 'integer',
            'effective_from' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public static function expiryYearsFor(string $version): int
    {
        return static::query()
            ->where('version', $version)
            ->where('is_active', true)
            ->value('expiry_years') ?? self::DEFAULT_EXPIRY_YEARS;
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
