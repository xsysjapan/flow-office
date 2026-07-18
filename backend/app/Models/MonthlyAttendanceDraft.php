<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 月次勤怠下書き(docs/26-usecases-monthly-import.md)。正式な月次勤怠(attendance_months)
 * とは分離した編集可能な候補。
 */
#[Fillable([
    'user_id', 'target_month', 'status', 'version', 'source_type', 'source_reference',
    'created_by_user_id', 'submitted_at',
])]
class MonthlyAttendanceDraft extends Model
{
    protected function casts(): array
    {
        return ['submitted_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<FieldProvenance, $this>
     */
    public function fieldProvenances(): HasMany
    {
        return $this->hasMany(FieldProvenance::class, 'entity_id')->where('entity_type', FieldProvenance::ENTITY_MONTHLY_ATTENDANCE_DRAFT);
    }
}
