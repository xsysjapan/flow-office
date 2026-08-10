<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 複数従業員予定の一括操作 (docs/16-database-schema.md calendar_bulk_operations、UC-C013)。
 * プレビュー→確定適用→取消のロジックは`App\Domain\Attendance\Services\CalendarBulkOperationPlanner`
 * と`CalendarBulkOperationAggregate`にまとめる。
 */
#[Fillable(['id', 'operation_type', 'target_scope', 'conflict_policy', 'status', 'requested_by_user_id', 'applied_at', 'reverted_at', 'reason'])]
class CalendarBulkOperation extends Model
{
    use HasUuids;

    public const OPERATION_CALENDAR_APPLY = 'calendar_apply';

    public const OPERATION_ROTATION_GENERATE = 'rotation_generate';

    public const OPERATION_BULK_EDIT = 'bulk_edit';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'target_scope' => 'array',
            'applied_at' => 'datetime',
            'reverted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /**
     * @return HasMany<CalendarBulkOperationTarget, $this>
     */
    public function targets(): HasMany
    {
        return $this->hasMany(CalendarBulkOperationTarget::class, 'calendar_bulk_operation_id');
    }
}
