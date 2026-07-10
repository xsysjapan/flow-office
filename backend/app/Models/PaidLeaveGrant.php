<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 有給付与 (docs/03-architecture.md 3.3: 勤怠の正の一つ)。
 */
#[Fillable(['user_id', 'granted_on', 'expires_on', 'granted_days', 'used_days', 'remaining_days', 'grant_reason', 'expiry_warned_at', 'five_day_obligation_warned_at'])]
class PaidLeaveGrant extends Model
{
    protected function casts(): array
    {
        return [
            'granted_on' => 'date',
            'expires_on' => 'date',
            'granted_days' => 'decimal:1',
            'used_days' => 'decimal:1',
            'remaining_days' => 'decimal:1',
            'expiry_warned_at' => 'datetime',
            'five_day_obligation_warned_at' => 'datetime',
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
     * @return HasMany<PaidLeaveUsage, $this>
     */
    public function usages(): HasMany
    {
        return $this->hasMany(PaidLeaveUsage::class, 'paid_leave_grant_id');
    }
}
