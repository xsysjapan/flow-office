<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RequestCenterItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'request_type' => $this->request_type,
            'source_id' => $this->source_id,
            'status' => $this->status,
            'requester_id' => $this->requester_id,
            'title' => $this->title,
            'amount_or_days' => $this->amount_or_days !== null ? (float) $this->amount_or_days : null,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
