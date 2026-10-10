<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * 特別休暇の消化記録(special_leave_usages.usage_id)と付与(special_leave_grants)の充当関係
 * (special_leave_usage_allocations)。特別休暇の口座Projector(SpecialLeaveAccountProjector)だけが書き込む
 * 派生データで、1つの消化記録が複数の付与にまたがる場合は付与ごとに行を持つ。
 */
#[Fillable(['usage_id', 'grant_id', 'allocated_days'])]
class SpecialLeaveUsageAllocation extends Model
{
    protected function casts(): array
    {
        return [
            'allocated_days' => 'decimal:1',
        ];
    }
}
