<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExpenseEntryPresetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'visibility' => $this->visibility,
            'owner_user_id' => $this->owner_user_id,
            'name' => $this->name,
            'description' => $this->description,
            'preset_type' => $this->preset_type,
            'definition' => $this->definition,
            'is_active' => $this->is_active,
            'usage_count' => $this->usage_count,
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'created_by' => $this->created_by,
        ];
    }
}
