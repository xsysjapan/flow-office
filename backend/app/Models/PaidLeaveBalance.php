<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 社員単位の現在残高キャッシュ。PaidLeaveBalanceProjectorがPaidLeaveAccountAggregateの
 * イベントから都度再集計する(表示専用。承認可否等の業務判定には使わない)。
 * 主キーはuser_id(1社員1行、DB採番でも自動UUID生成でもなく常にコマンド側のuserIdを使う)。
 */
#[Fillable(['user_id', 'available_days', 'pending_days', 'unallocated_days', 'next_grant_scheduled_on'])]
class PaidLeaveBalance extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $primaryKey = 'user_id';

    protected function casts(): array
    {
        return [
            'available_days' => 'decimal:1',
            'pending_days' => 'decimal:1',
            'unallocated_days' => 'decimal:1',
            'next_grant_scheduled_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
