<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 一括操作の対象明細 (docs/16-database-schema.md calendar_bulk_operation_targets、UC-C013)。
 */
#[Fillable(['id', 'calendar_bulk_operation_id', 'user_id', 'work_date', 'employee_calendar_entry_id', 'result', 'error_code', 'previous_snapshot'])]
class CalendarBulkOperationTarget extends Model
{
    use HasUuids;

    public const RESULT_APPLIED = 'applied';

    public const RESULT_SKIPPED_EXISTING = 'skipped_existing';

    public const RESULT_FAILED = 'failed';

    public $incrementing = false;

    protected $keyType = 'string';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'previous_snapshot' => 'array',
        ];
    }

    /**
     * @return BelongsTo<CalendarBulkOperation, $this>
     */
    public function bulkOperation(): BelongsTo
    {
        return $this->belongsTo(CalendarBulkOperation::class, 'calendar_bulk_operation_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<EmployeeCalendarEntry, $this>
     */
    public function employeeCalendarEntry(): BelongsTo
    {
        return $this->belongsTo(EmployeeCalendarEntry::class, 'employee_calendar_entry_id');
    }
}
