<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 有給申請 (docs/09-usecases-paid-leave.md UC-P003/UC-P004)。承認とバックオフィス処理を
 * 別ステータス系列で管理する方針と同様、汎用申請(workflow_requests)とは独立したステータス
 * 系列として持つ(承認時に attendance_days / paid_leave_grants への反映が必要なため)。
 */
#[Fillable(['user_id', 'approver_user_id', 'status', 'leave_type', 'target_date', 'hours', 'requested_days', 'reason', 'submitted_at', 'approved_at', 'returned_at', 'cancelled_at'])]
class PaidLeaveRequest extends Model
{
    protected function casts(): array
    {
        return [
            'target_date' => 'date',
            'hours' => 'decimal:2',
            'requested_days' => 'decimal:1',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'returned_at' => 'datetime',
            'cancelled_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }
}
