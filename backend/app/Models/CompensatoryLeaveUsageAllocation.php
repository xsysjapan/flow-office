<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * 代休の消化記録(usage_id)と付与(grant_id)の充当の表。代休口座のProjectorだけが書き込む派生データ
 * (1つの消化記録が複数の付与にまたがる場合は付与ごとに行を作る)。
 */
#[Fillable(['usage_id', 'grant_id', 'allocated_days', 'allocated_minutes'])]
class CompensatoryLeaveUsageAllocation extends Model
{
    protected function casts(): array
    {
        return [
            'allocated_days' => 'decimal:1',
            'allocated_minutes' => 'integer',
        ];
    }
}
