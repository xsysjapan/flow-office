<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 認証キーの生値(key_hash)は返さない(docs/24-usecases-authentication-keys.md
 * 「セキュリティ要件」)。
 */
class AuthenticationKeyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'key_type' => $this->key_type,
            'display_name' => $this->display_name,
            'status' => $this->status,
            'valid_from' => $this->valid_from?->toIso8601String(),
            'valid_until' => $this->valid_until?->toIso8601String(),
            'registered_by_user_id' => $this->registered_by_user_id,
            'registered_at' => $this->registered_at?->toIso8601String(),
            'disabled_at' => $this->disabled_at?->toIso8601String(),
        ];
    }
}
