<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 「申請センター」画面向けの横断Projection(App\Domain\RequestCenter\Projectors\
 * RequestCenterItemProjector が再生成する)。paid_leave_requests / compensatory_leave_requests /
 * expense_claims / workflow_requests の4ドメインの申請を1行1件、申請種別横断で持つ。
 */
class RequestCenterItem extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'request_type',
        'source_id',
        'status',
        'requester_id',
        'approver_id',
        'title',
        'submitted_at',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
    ];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
}
