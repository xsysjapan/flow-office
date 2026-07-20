<?php

namespace App\Http\Resources;

use App\Models\SystemSetting;
use App\Support\LocalDateTime;
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
            'sso_linked' => $this->entra_user_id !== null,
            'department' => $this->department,
            'job_title' => $this->job_title,
            'employment_status' => $this->employment_status,
            'timezone' => $this->timezone,
            'hire_date' => $this->hire_date?->toDateString(),
            'termination_date' => $this->termination_date?->toDateString(),
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->pluck('code')),
            // last_login_atのような一般的な日時はシステムのデフォルトタイムゾーンのオフセットで
            // 送信し、画面表示では本人のタイムゾーン(timezone)に変換して表示する
            // (docs/03-architecture.md 3.4)。
            'last_login_at' => LocalDateTime::toIso8601($this->last_login_at, SystemSetting::current()->default_timezone),
        ];
    }
}
