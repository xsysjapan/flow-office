<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 振替休日申請(SpecialLeaveRequestと同じ形)。承認とバックオフィス処理を別ステータス系列
 * で管理する方針と同様、汎用申請(workflow_requests)とは独立したステータス系列として持つ
 * (承認時に employee_calendar_entries への反映が必要なため)。主キーはUUID(HasUuids)。
 * 理由はSpecialLeaveRequestと同じ。
 */
#[Fillable(['id', 'user_id', 'target_date', 'substitute_date', 'approver_user_id', 'status', 'reason', 'return_comment', 'submitted_at', 'approved_at', 'returned_at', 'cancelled_at'])]
class ShiftSwapRequest extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'target_date' => 'date',
            'substitute_date' => 'date',
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
