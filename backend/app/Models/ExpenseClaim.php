<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * UC-X010〜UC-X012: 経費精算ヘッダー。通勤費・業務交通費・その他経費すべてを扱う単一ドメイン
 * (docs/30-usecases-expense.md)。承認とバックオフィス処理は別ステータス系列
 * (backoffice_tasks) で管理するため、ここでのstatusは承認フローのみを表す。
 *
 * 主キーはUUID(HasUuids)。ExpenseClaimAggregateが発番し、行の新規作成を含めて
 * ExpenseClaimProjectorがstored_eventsから作成・更新する。
 */
#[Fillable([
    'id', 'employee_id', 'title', 'period_from', 'period_to', 'status',
    'approver_user_id', 'total_amount', 'submitted_at', 'approved_at',
])]
class ExpenseClaim extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'period_from' => 'date',
            'period_to' => 'date',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }

    /**
     * @return HasMany<ExpenseItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ExpenseItem::class, 'claim_id');
    }
}
