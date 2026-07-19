<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'import_session_id', 'work_date', 'proposed_data_json', 'existing_data_json',
    'differences_json', 'validation_result_json', 'confidence', 'status', 'source_reference_json',
])]
class AttendanceImportItem extends Model
{
    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'proposed_data_json' => 'array',
            'existing_data_json' => 'array',
            'differences_json' => 'array',
            'validation_result_json' => 'array',
            'source_reference_json' => 'array',
        ];
    }

    /**
     * @return BelongsTo<AttendanceImportSession, $this>
     */
    public function importSession(): BelongsTo
    {
        return $this->belongsTo(AttendanceImportSession::class, 'import_session_id');
    }

    public function hasBlockingDifferences(): bool
    {
        foreach ($this->differences_json ?? [] as $difference) {
            if (($difference['severity'] ?? 'error') === 'error') {
                return true;
            }
        }

        return false;
    }

    /**
     * severityを問わず何らかの差異が検出されているか。「本人確認済みとして自動反映してよいか」
     * の判定に使う(docs/03-architecture.md 3.7「AIは勤怠ルールを決定しない」)。
     */
    public function hasAnyDifferences(): bool
    {
        return ! empty($this->differences_json);
    }
}
