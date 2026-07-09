<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'department' => $this->department,
            'job_title' => $this->job_title,
            'employment_status' => $this->employment_status,
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->pluck('code')),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
        ];
    }
}
