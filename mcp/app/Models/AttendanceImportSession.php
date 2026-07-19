<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 作業報告書インポートセッション(docs/26-usecases-monthly-import.md)。mcp/自身のDBに保持する。
 * ファイル解析自体はClaude側で行い、構造化データのみをここで受け取る。
 */
#[Fillable([
    'user_id', 'target_month', 'status', 'source_type', 'source_file_name', 'source_file_hash',
    'client_type', 'monthly_attendance_draft_id',
])]
class AttendanceImportSession extends Model
{
    /**
     * @return BelongsTo<McpUser, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(McpUser::class, 'user_id');
    }

    /**
     * @return HasMany<AttendanceImportItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(AttendanceImportItem::class, 'import_session_id');
    }

    /**
     * @return BelongsTo<MonthlyAttendanceDraft, $this>
     */
    public function monthlyAttendanceDraft(): BelongsTo
    {
        return $this->belongsTo(MonthlyAttendanceDraft::class);
    }
}
