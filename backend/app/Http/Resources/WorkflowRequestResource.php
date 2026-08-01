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
            default => null,
        };
    }
}
