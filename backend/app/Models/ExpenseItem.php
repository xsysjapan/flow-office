<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * UC-X004〜UC-X009: 経費明細。通勤費・業務交通費・その他経費(宿泊費・会食・消耗品等)を
 * 単一のデータ構造で表現する(docs/30-usecases-expense.md)。
 *
 * 主キーはUUID(HasUuids)。ExpenseClaimAggregateが発番し、ExpenseClaimProjectorが
 * stored_eventsから作成・更新・削除する。添付ファイルは新規テーブルを持たず、既存の
 * Attachment集約をowner_type='ExpenseItem'として再利用する。
 */
#[Fillable([
    'id', 'claim_id', 'category_id', 'usage_date', 'origin', 'destination',
    'transport_type', 'amount', 'destination_name', 'purpose', 'project_id',
    'evidence_type', 'fact_reference_type', 'fact_reference_id', 'commuting_deduction_amount',
])]
class ExpenseItem extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'usage_date' => 'date',
        ];
    }

    /**
     * 会社負担額(UC-X009: 定期区間との重複自己申告分を控除した金額)。
     */
    public function netAmount(): int
    {
        return $this->amount - $this->commuting_deduction_amount;
    }

    /**
     * @return BelongsTo<ExpenseClaim, $this>
     */
    public function claim(): BelongsTo
    {
        return $this->belongsTo(ExpenseClaim::class, 'claim_id');
    }

    /**
     * @return BelongsTo<ExpenseCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id');
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'owner');
    }
}
