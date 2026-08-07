<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 代休の消化(使用)申請(SpecialLeaveRequestと同じ形)。承認とバックオフィス処理を
 * 別ステータス系列で管理する方針と同様、汎用申請(workflow_requests)とは独立した
 * ステータス系列として持つ(承認時にattendance_days/compensatory_leave_grantsへの
 * 反映が必要なため)。主キーはUUID(HasUuids)。
 */
#[Fillable(['id', 'request_group_id', 'user_id', 'approver_user_id', 'status', 'leave_type', 'target_date', 'hours', 'requested_days', 'requested_minutes', 'reason', 'submitted_at', 'approved_at', 'returned_at', 'cancelled_at'])]
class CompensatoryLeaveRequest extends Model
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
}
