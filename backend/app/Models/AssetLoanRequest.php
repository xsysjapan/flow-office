<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 貸出申請の読み取り専用Projection(spec 論点2)。idはworkflow_requests.idと同一。
 * 書き込みは`App\Domain\Asset\Reactors\`配下のReactorのみが行う。
 */
#[Fillable([
    'id', 'asset_id', 'applicant_user_id', 'approver_user_id', 'status', 'purpose',
    'submitted_at', 'approved_at', 'rejected_at', 'rejection_reason',
    'withdrawn_at', 'cancelled_at', 'lent_at',
])]
class AssetLoanRequest extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'lent_at' => 'datetime',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applicant_user_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }
}
