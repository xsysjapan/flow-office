<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 貸出品の貸出/返却履歴 (spec「仕様確定事項」)。主キーはUUID(`asset.loaned`イベントの
 * `loanId`と一致させる。AssetProjector参照)。
 */
#[Fillable([
    'id', 'asset_id', 'user_id', 'loan_request_id', 'loaned_at', 'expected_return_at',
    'loaned_by_user_id', 'returned_at', 'returned_by_user_id', 'return_note',
])]
class AssetLoan extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'loaned_at' => 'datetime',
            'expected_return_at' => 'datetime',
            'returned_at' => 'datetime',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
