<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExpenseCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'entry_mode' => $this->entry_mode,
            'evidence_type_default' => $this->evidence_type_default,
            'receipt_required_threshold' => $this->receipt_required_threshold,
            'approval_skip_threshold' => $this->approval_skip_threshold,
            'is_active' => $this->is_active,
        ];
    }
}
