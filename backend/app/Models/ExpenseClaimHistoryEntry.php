<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 経費精算の履歴表示用Projection。stored_eventsのイベントクラス名・payload形状を
 * UIに直接公開しないための専用テーブル(docs/29-event-sourcing-framework-migration.md参照)。
 */
#[Fillable(['stored_event_id', 'expense_claim_id', 'action', 'actor_user_id', 'comment', 'occurred_at'])]
class ExpenseClaimHistoryEntry extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ExpenseClaim, $this>
     */
    public function expenseClaim(): BelongsTo
    {
        return $this->belongsTo(ExpenseClaim::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
