<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Schedule Entry 1件に対して複数回記録されうる出勤率Assessmentの版履歴Projection
 * (spec.md 論点2)。主キーはCommandHandler側で発番されるUUID(assessmentId)。
 */
#[Fillable(['id', 'schedule_entry_id', 'period_start', 'period_end', 'denominator_days', 'attendance_days', 'excluded_days', 'attendance_rate', 'policy_version', 'automatic_result', 'final_result', 'override_reason', 'overridden_by_user_id'])]
class PaidLeaveScheduleAssessment extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'denominator_days' => 'integer',
            'attendance_days' => 'integer',
            'excluded_days' => 'integer',
            'attendance_rate' => 'float',
        ];
    }

    /**
     * @return BelongsTo<PaidLeaveScheduleEntry, $this>
     */
    public function scheduleEntry(): BelongsTo
    {
        return $this->belongsTo(PaidLeaveScheduleEntry::class, 'schedule_entry_id');
    }
}
