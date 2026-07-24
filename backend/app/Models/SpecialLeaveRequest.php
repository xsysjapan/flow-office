<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 特別休暇申請(PaidLeaveRequestと同じ形)。承認とバックオフィス処理を別ステータス系列
 * で管理する方針と同様、汎用申請(workflow_requests)とは独立したステータス系列として持つ
 * (承認時に attendance_days / special_leave_grants への反映が必要なため)。主キーはUUID
 * (HasUuids)。理由はPaidLeaveRequestと同じ。
 */
#[Fillable(['id', 'user_id', 'special_leave_type_id', 'approver_user_id', 'status', 'leave_type', 'target_date', 'hours', 'requested_days', 'reason', 'submitted_at', 'approved_at', 'returned_at', 'cancelled_at'])]
class SpecialLeaveRequest extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

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

    /**
     * @return BelongsTo<SpecialLeaveType, $this>
     */
    public function specialLeaveType(): BelongsTo
    {
        return $this->belongsTo(SpecialLeaveType::class);
    }
}
