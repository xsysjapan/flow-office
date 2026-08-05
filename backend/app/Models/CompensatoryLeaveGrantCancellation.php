<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 代休Grant(未使用分のみ)の取消申請の進捗管理。日次勤怠の消化申請とは別軸のため
 * workflow_requestsへは連携せず、この専用テーブルで最小限に管理する
 * (Grant自体の状態変更はCompensatoryLeaveGrantAggregate経由でイベントソーシングする)。
 */
#[Fillable(['grant_id', 'requested_by_user_id', 'approver_user_id', 'status', 'reason', 'approved_at'])]
class CompensatoryLeaveGrantCancellation extends Model
{
    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<CompensatoryLeaveGrant, $this>
     */
    public function grant(): BelongsTo
    {
        return $this->belongsTo(CompensatoryLeaveGrant::class, 'grant_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }
}
