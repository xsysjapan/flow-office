<?php

namespace App\Models;

use App\Domain\PaidLeaveSchedule\Aggregates\PaidLeaveScheduleAggregate;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * `App\Domain\PaidLeaveSchedule\Aggregates\PaidLeaveScheduleAggregate`のProjection
 * (spec.md 実装対象Phase B)。主キーはCommandHandler側で発番されるUUID
 * (scheduleEntryId)であり、行の新規作成自体もProjector経由で行う。
 */
#[Fillable(['id', 'user_id', 'scheduled_on', 'category', 'candidate_grant_days', 'status', 'latest_assessment_id', 'is_manually_overridden', 'manual_override_reason', 'manual_override_by_user_id', 'manual_override_at', 'grant_id', 'cancelled_reason'])]
class PaidLeaveScheduleEntry extends Model
{
    use HasUuids;

    public const STATUS_SCHEDULED = PaidLeaveScheduleAggregate::STATUS_SCHEDULED;

    public const STATUS_ASSESSMENT_PENDING = PaidLeaveScheduleAggregate::STATUS_ASSESSMENT_PENDING;

    public const STATUS_ELIGIBLE = PaidLeaveScheduleAggregate::STATUS_ELIGIBLE;

    public const STATUS_NOT_ELIGIBLE = PaidLeaveScheduleAggregate::STATUS_NOT_ELIGIBLE;

    public const STATUS_NEEDS_REVIEW = PaidLeaveScheduleAggregate::STATUS_NEEDS_REVIEW;

    public const STATUS_GRANTED = PaidLeaveScheduleAggregate::STATUS_GRANTED;

    public const STATUS_CANCELLED = PaidLeaveScheduleAggregate::STATUS_CANCELLED;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'scheduled_on' => 'date',
            'candidate_grant_days' => 'float',
            'is_manually_overridden' => 'boolean',
            'manual_override_at' => 'datetime',
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
     * @return HasMany<PaidLeaveScheduleAssessment, $this>
     */
    public function assessments(): HasMany
    {
        return $this->hasMany(PaidLeaveScheduleAssessment::class, 'schedule_entry_id')->orderBy('created_at');
    }
}
