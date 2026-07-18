<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApplicationIntegrationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'owner_type' => $this->owner_type,
            'owner_user_id' => $this->owner_user_id,
            'client_type' => $this->client_type,
            'client_name' => $this->client_name,
            'purpose' => $this->purpose,
            'status' => $this->status,
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'scopes' => $this->whenLoaded('scopes', fn () => $this->scopes->pluck('scope')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
