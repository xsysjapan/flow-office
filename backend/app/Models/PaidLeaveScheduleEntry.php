<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `App\Domain\PaidLeaveSchedule\Aggregates\PaidLeaveScheduleAggregate`のEntry状態を
 * 画面表示用に射影したProjection(CLAUDE.md原則2)。Source of Truthは`stored_events`
 * であり、このモデルへの直接書き込みは
 * `App\Domain\PaidLeaveSchedule\Projectors\PaidLeaveScheduleEntryProjector`のみが行う。
 */
class PaidLeaveScheduleEntry extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'user_id',
        'scheduled_on',
        'category',
        'candidate_grant_days',
        'status',
        'manual_override_reason',
        'manual_override_by_user_id',
        'manual_override_at',
        'assessment_period_start',
        'assessment_period_end',
        'assessment_denominator_days',
        'assessment_attendance_days',
        'assessment_excluded_days',
        'assessment_attendance_rate',
        'assessment_policy_version',
        'assessment_automatic_result',
        'assessment_final_result',
        'assessment_override_reason',
        'granted_paid_leave_grant_id',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_on' => 'date',
            'manual_override_at' => 'datetime',
            'assessment_period_start' => 'date',
            'assessment_period_end' => 'date',
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
     * @return BelongsTo<PaidLeaveGrant, $this>
     */
    public function grant(): BelongsTo
    {
        return $this->belongsTo(PaidLeaveGrant::class, 'granted_paid_leave_grant_id');
    }

    /**
     * 「変更・要確認」表示(spec.md論点12・7): 個別修正済みだった(=Overrideまたは
     * 手動編集の記録がある)エントリが、その後の再計算で新しい算出結果と食い違い
     * NeedsReviewへ押し出されたことを示す。専用の永続化列は持たず、既存列から導出する
     * (CLAUDE.md原則2)。
     */
    public function needsReviewDueToConflict(): bool
    {
        return $this->status === \App\Domain\PaidLeaveSchedule\Support\ScheduleEntryStatus::NEEDS_REVIEW
            && $this->manual_override_by_user_id !== null;
    }
}
