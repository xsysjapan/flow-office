<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 有給消化 (docs/03-architecture.md 3.3: 勤怠の正の一つ)。有効期限が近い付与分から
 * 優先的に消し込むため、1件の有給申請の承認が複数のpaid_leave_grantにまたがる場合、
 * grantごとに1行作成される。
 */
#[Fillable(['stored_event_id', 'user_id', 'attendance_day_id', 'paid_leave_grant_id', 'paid_leave_request_id', 'used_on', 'used_days', 'used_minutes', 'usage_type'])]
class PaidLeaveUsage extends Model
{
    protected function casts(): array
    {
        return [
            'used_on' => 'date',
            'used_days' => 'decimal:1',
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
     * @return BelongsTo<PaidLeaveGrant, $this>
     */
    public function grant(): BelongsTo
    {
        return $this->belongsTo(PaidLeaveGrant::class, 'paid_leave_grant_id');
    }
}
