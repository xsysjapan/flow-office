<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeviceResource extends JsonResource
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
            'name' => $this->name,
            'device_type' => $this->device_type,
            'status' => $this->status,
            'site_id' => $this->site_id,
            'location_name' => $this->location_name,
            'default_work_location_type' => $this->default_work_location_type,
            'timezone' => $this->timezone,
            'allowed_punch_types' => $this->allowed_punch_types,
            'allow_offline' => $this->allow_offline,
            'require_location' => $this->require_location,
            'auto_detect_punch_type' => $this->auto_detect_punch_type,
            'app_version' => $this->app_version,
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'paired_at' => $this->paired_at?->toIso8601String(),
            'disabled_at' => $this->disabled_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->pluck('role_type')),
            'scopes' => $this->whenLoaded('scopes', fn () => $this->scopes->pluck('scope')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
