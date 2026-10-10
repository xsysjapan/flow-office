<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * 勤怠側の代休付与のビューの充当の表(消化記録×付与)。CompensatoryGrantDayViewProjectorだけが書き込む。
 */
#[Fillable(['usage_id', 'grant_id', 'allocated_days', 'allocated_minutes'])]
class CompensatoryGrantDayViewAllocation extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'allocated_days' => 'decimal:1',
            'allocated_minutes' => 'integer',
        ];
    }
}
