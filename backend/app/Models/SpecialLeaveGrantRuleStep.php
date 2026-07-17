<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['rule_id', 'continuous_service_months', 'grant_days'])]
class SpecialLeaveGrantRuleStep extends Model
{
    /**
     * @return BelongsTo<SpecialLeaveGrantRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(SpecialLeaveGrantRule::class, 'rule_id');
    }
}
