<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['rule_id', 'continuous_service_months', 'grant_days'])]
class PaidLeaveGrantRuleStep extends Model
{
    /**
     * @return BelongsTo<PaidLeaveGrantRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(PaidLeaveGrantRule::class, 'rule_id');
    }
}
