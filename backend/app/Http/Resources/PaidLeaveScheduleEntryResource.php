<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `PaidLeaveScheduleEntry`(Projection)の整形。一覧・詳細で同じフィールド集合を返す
 * (spec.md画面設計: 一覧テーブルの列も判定状態の判断材料として出勤率・区分等を表示するため、
 * 詳細向けに削る必要が無い。既存`PaidLeaveGrantRuleResource`と同様、List/Detailを分けず
 * 1つのResourceに統一する)。
 */
class PaidLeaveScheduleEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'user_name' => $this->whenLoaded('user', fn () => $this->user?->name),
            'scheduled_on' => optional($this->scheduled_on)->toDateString(),
            'category' => $this->category,
            'candidate_grant_days' => $this->candidate_grant_days !== null ? (float) $this->candidate_grant_days : null,
            'status' => $this->status,
            'needs_review_due_to_conflict' => $this->needsReviewDueToConflict(),
            'manual_override_reason' => $this->manual_override_reason,
            'manual_override_by_user_id' => $this->manual_override_by_user_id,
            'manual_override_at' => optional($this->manual_override_at)->toIso8601String(),
            'granted_paid_leave_grant_id' => $this->granted_paid_leave_grant_id,
            'assessment' => [
                'period_start' => optional($this->assessment_period_start)->toDateString(),
                'period_end' => optional($this->assessment_period_end)->toDateString(),
                'denominator_days' => $this->assessment_denominator_days,
                'attendance_days' => $this->assessment_attendance_days,
                'excluded_days' => $this->assessment_excluded_days,
                'attendance_rate' => $this->assessment_attendance_rate !== null ? (float) $this->assessment_attendance_rate : null,
                'policy_version' => $this->assessment_policy_version,
                'automatic_result' => $this->assessment_automatic_result,
                'final_result' => $this->assessment_final_result,
                'override_reason' => $this->assessment_override_reason,
            ],
        ];
    }
}
