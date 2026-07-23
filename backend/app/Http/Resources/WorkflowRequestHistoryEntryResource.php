<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkflowRequestHistoryEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'actor_user_id' => $this->actor_user_id,
            'comment' => $this->comment,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
        ];
    }
}
