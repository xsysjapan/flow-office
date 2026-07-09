<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * 勤務実績(日次) (docs/03-architecture.md 3.3: 勤怠の正)。
 */
#[Fillable(['user_id', 'work_date', 'shift_assignment_id', 'status', 'actual_start_at', 'actual_end_at', 'work_type', 'note', 'locked_at'])]
class AttendanceDay extends Model
{
    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'actual_start_at' => 'datetime',
            'actual_end_at' => 'datetime',
            'locked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<EmployeeShiftAssignment, $this>
     */
    public function shiftAssignment(): BelongsTo
    {
        return $this->belongsTo(EmployeeShiftAssignment::class, 'shift_assignment_id');
    }

    /**
     * @return HasMany<AttendanceBreak, $this>
     */
    public function breaks(): HasMany
    {
        return $this->hasMany(AttendanceBreak::class);
    }

    /**
     * @return HasOne<AttendanceDailyCalculation, $this>
     */
    public function calculation(): HasOne
    {
        return $this->hasOne(AttendanceDailyCalculation::class);
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }
}
