<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 有給付与ルール (docs/09-usecases-paid-leave.md UC-P001)。
 * 法務判断が必要な値のためマスタ化し、ハードコードしない。
 */
#[Fillable(['name', 'work_style_id', 'min_attendance_rate', 'first_grant_after_months', 'grant_cycle_months', 'is_active'])]
class PaidLeaveGrantRule extends Model
{
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<WorkStyle, $this>
     */
    public function workStyle(): BelongsTo
    {
        return $this->belongsTo(WorkStyle::class);
    }

    /**
     * @return HasMany<PaidLeaveGrantRuleStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(PaidLeaveGrantRuleStep::class, 'rule_id')->orderBy('continuous_service_months');
    }
}
