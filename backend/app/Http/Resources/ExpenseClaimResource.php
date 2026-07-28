<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExpenseClaimResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'employee' => new UserResource($this->whenLoaded('employee')),
            'title' => $this->title,
            'period_from' => $this->period_from?->toDateString(),
            'period_to' => $this->period_to?->toDateString(),
            'status' => $this->status,
            'approver_user_id' => $this->approver_user_id,
            'approver' => new UserResource($this->whenLoaded('approver')),
            'total_amount' => $this->total_amount,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'items' => ExpenseItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
