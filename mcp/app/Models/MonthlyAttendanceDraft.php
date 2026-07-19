<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 月次勤怠下書き(docs/26-usecases-monthly-import.md)。mcp/自身のDBに保持し、backend/には
 * 一切書き込まない。ユーザーが明示的に申請を指示した時点でのみ、backend/の既存API
 * (日次編集・月次提出)を呼び出して正データ(attendance_days/attendance_months)を作成する。
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
     * @return BelongsTo<McpUser, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(McpUser::class, 'user_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<FieldProvenance, $this>
     */
    public function fieldProvenances(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(FieldProvenance::class, 'entity_id')->where('entity_type', FieldProvenance::ENTITY_MONTHLY_ATTENDANCE_DRAFT);
    }
}
