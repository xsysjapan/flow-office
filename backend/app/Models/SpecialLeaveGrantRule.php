<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 特別休暇種別ごとの自動付与ルール(PaidLeaveGrantRuleと同じ形)。
 */
#[Fillable(['special_leave_type_id', 'name', 'work_style_id', 'min_attendance_rate', 'first_grant_after_months', 'grant_cycle_months', 'expires_after_months', 'is_active'])]
class SpecialLeaveGrantRule extends Model
{
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<SpecialLeaveType, $this>
     */
    public function specialLeaveType(): BelongsTo
    {
        return $this->belongsTo(SpecialLeaveType::class);
    }

    /**
     * @return BelongsTo<WorkStyle, $this>
     */
    public function workStyle(): BelongsTo
    {
        return $this->belongsTo(WorkStyle::class);
    }

    /**
     * @return HasMany<SpecialLeaveGrantRuleStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(SpecialLeaveGrantRuleStep::class, 'rule_id')->orderBy('continuous_service_months');
    }
}
