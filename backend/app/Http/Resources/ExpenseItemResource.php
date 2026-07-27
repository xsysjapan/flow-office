<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExpenseItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'claim_id' => $this->claim_id,
            'category_id' => $this->category_id,
            'usage_date' => $this->usage_date?->toDateString(),
            'description' => $this->description,
            'amount' => $this->amount,
            'project_id' => $this->project_id,
            'evidence_type' => $this->evidence_type,
            'fact_reference_type' => $this->fact_reference_type,
            'fact_reference_id' => $this->fact_reference_id,
            'commuting_deduction_amount' => $this->commuting_deduction_amount,
            'net_amount' => $this->netAmount(),
            'attachments' => AttachmentResource::collection($this->whenLoaded('attachments')),
        ];
    }
}
