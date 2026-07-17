<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 特別休暇の付与(PaidLeaveGrantと同じ形)。有給と異なり法定の時効がないため、
 * expires_onはnullable(null=失効しない)。
 */
#[Fillable(['user_id', 'special_leave_type_id', 'granted_on', 'expires_on', 'granted_days', 'used_days', 'remaining_days', 'grant_reason'])]
class SpecialLeaveGrant extends Model
{
    protected function casts(): array
    {
        return [
            'granted_on' => 'date',
            'expires_on' => 'date',
            'granted_days' => 'decimal:1',
            'used_days' => 'decimal:1',
            'remaining_days' => 'decimal:1',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<SpecialLeaveType, $this>
     */
    public function specialLeaveType(): BelongsTo
    {
        return $this->belongsTo(SpecialLeaveType::class);
    }

    /**
     * @return HasMany<SpecialLeaveUsage, $this>
     */
    public function usages(): HasMany
    {
        return $this->hasMany(SpecialLeaveUsage::class, 'special_leave_grant_id');
    }

    /**
     * 指定日時点で消化可能な付与(失効日が無い、または対象日以降に失効する)に絞り込む
     * 読み取り専用のQuery。有給・特別休暇双方の残高集計・消化順の判定で使う共通の形
     * (有給側はexpires_onが必須のためこのスコープは使わない)。
     *
     * @param  Builder<SpecialLeaveGrant>  $query
     * @return Builder<SpecialLeaveGrant>
     */
    public function scopeAvailableOn(Builder $query, string $date): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('expires_on')->orWhereDate('expires_on', '>=', $date));
    }
}
