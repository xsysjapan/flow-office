<?php

namespace App\Http\Resources;

use App\Domain\BackOffice\Support\BackOfficeTaskTransitionPolicy;
use App\Models\BackOfficeTaskStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BackOfficeTaskResource extends JsonResource
{
    private bool $includeAllowedNextStatuses = false;

    /**
     * 詳細系(show・assign・changeStatus)のレスポンスに allowed_next_statuses を含める。
     * 一覧(index・bulkComplete)では含めない(発生源の申請種別を読み込まないため)。
     */
    public function withAllowedNextStatuses(): self
    {
        $this->includeAllowedNextStatuses = true;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'task_type' => $this->task_type,
            'title' => $this->title,
            'status' => $this->status,
            'assigned_department' => $this->assigned_department,
            'assignee' => new UserResource($this->whenLoaded('assignee')),
            'due_on' => $this->due_on?->toDateString(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'allowed_next_statuses' => $this->when($this->includeAllowedNextStatuses, fn () => $this->allowedNextStatuses()),
        ];
    }

    /**
     * 現在の状態を除く全ステータスのうち、遷移ルールで移れるもの(BackOfficeTaskStatus::all()の順)。
     * `source` は呼び出し元で読み込み済みであること。
     *
     * @return array<int, string>
     */
    private function allowedNextStatuses(): array
    {
        $policy = new BackOfficeTaskTransitionPolicy;

        return array_values(array_filter(
            BackOfficeTaskStatus::all(),
            fn (string $status): bool => $status !== $this->status && $policy->isAllowed($this->resource, $status),
        ));
    }
}
