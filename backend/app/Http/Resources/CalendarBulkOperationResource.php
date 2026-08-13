<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CalendarBulkOperationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'operation_type' => $this->operation_type,
            'target_scope' => $this->target_scope,
            'conflict_policy' => $this->conflict_policy,
            'status' => $this->status,
            'requested_by_user_id' => $this->requested_by_user_id,
            'applied_at' => $this->applied_at?->toIso8601String(),
            'reverted_at' => $this->reverted_at?->toIso8601String(),
            'reason' => $this->reason,
            'targets' => CalendarBulkOperationTargetResource::collection($this->whenLoaded('targets')),
        ];
    }
}
