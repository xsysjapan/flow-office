<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkflowRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'status' => $this->status,
            'form_data' => $this->form_data,
            'request_type' => new RequestTypeResource($this->whenLoaded('requestType')),
            'applicant' => new UserResource($this->whenLoaded('applicant')),
            'approver' => new UserResource($this->whenLoaded('approver')),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'returned_at' => $this->returned_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'rejected_at' => $this->rejected_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            'created_at' => $this->created_at?->toIso8601String(),
            'attachments' => AttachmentResource::collection($this->whenLoaded('attachments')),
            // subject_type/subject_idを持つ行(月次勤怠申請・経費精算申請)は、対象ドメインの
            // 正データを指す申請(DraftWorkflowRequestにsubjectを渡して作成される)。
            // 一覧では軽量な要約のみ返し、詳細情報はshow()側でsubject_summaryではなく
            // 別のsubjectキー(該当ドメインの全体データ)として組み立てる。
            'subject_type' => $this->subject_type,
            'subject_summary' => $this->when(
                $this->subject_type !== null,
                fn () => $this->buildSubjectSummary(),
            ),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildSubjectSummary(): ?array
    {
        $subject = $this->resource->subjectModel();

        if ($subject === null) {
            return null;
        }

        return match ($this->subject_type) {
            'attendance_month' => [
                'year_month' => $subject->year_month,
                'status' => $subject->status,
            ],
            'expense_claim' => [
                'title' => $subject->title,
                'status' => $subject->status,
                'total_amount' => $subject->total_amount,
            ],
            'paid_leave_request' => [
                'target_date' => $subject->target_date?->toDateString(),
                'leave_type' => $subject->leave_type,
                'leave_type_label' => $this->leaveTypeLabel($subject->leave_type),
                'hours' => $subject->hours !== null ? (float) $subject->hours : null,
                'requested_days' => (float) $subject->requested_days,
                'reason' => $subject->reason,
            ],
            'special_leave_request' => [
                'target_date' => $subject->target_date?->toDateString(),
                'leave_type' => $subject->leave_type,
                'leave_type_label' => $this->leaveTypeLabel($subject->leave_type),
                'special_leave_type_name' => $subject->specialLeaveType?->name,
                'hours' => $subject->hours !== null ? (float) $subject->hours : null,
                'requested_days' => (float) $subject->requested_days,
                'reason' => $subject->reason,
            ],
            'compensatory_leave_request' => [
                'target_date' => $subject->target_date?->toDateString(),
                'leave_type' => $subject->leave_type,
                'leave_type_label' => $this->leaveTypeLabel($subject->leave_type),
                'hours' => $subject->hours !== null ? (float) $subject->hours : null,
                'requested_days' => (float) $subject->requested_days,
                'reason' => $subject->reason,
            ],
            default => null,
        };
    }

    /**
     * PaidLeaveRequest/SpecialLeaveRequestの`leave_type`(full/am_half/pm_half/hourly)を
     * 日本語ラベルに変換する。frontend/src/utils/statusLabels.tsの
     * paidLeaveTypeLabelsと表記を揃えている。
     */
    public static function leaveTypeLabel(?string $leaveType): ?string
    {
        return match ($leaveType) {
            'full' => '全休',
            'am_half' => '午前半休',
            'pm_half' => '午後半休',
            'hourly' => '時間休',
            default => null,
        };
    }
}
