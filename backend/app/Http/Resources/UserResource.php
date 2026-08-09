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
            'employee_number' => $this->employee_number,
            'account_status' => $this->account_status,
            'source_type' => $this->source_type,
            'sso_linked' => $this->relationLoaded('externalIdentities')
                ? $this->externalIdentities->contains(fn ($identity) => $identity->provider === 'MICROSOFT_ENTRA' && $identity->status === 'active')
                : $this->entra_user_id !== null,
            'department' => $this->department,
            'job_title' => $this->job_title,
            'employment_status' => $this->employment_status,
            'timezone' => $this->timezone,
            'hire_date' => $this->hire_date?->toDateString(),
            'termination_date' => $this->termination_date?->toDateString(),
            'usage_start_date' => $this->usage_start_date?->toDateString(),
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->pluck('code')),
            'external_identities' => $this->whenLoaded('externalIdentities', fn () => $this->externalIdentities->map(fn ($identity) => [
                'id' => $identity->id,
                'provider' => $identity->provider,
                'external_tenant_id' => $identity->external_tenant_id,
                'external_subject_id' => $identity->external_subject_id,
                'email' => $identity->email,
                'status' => $identity->status,
                'last_synced_at' => $identity->last_synced_at?->toISOString(),
            ])),
            'memberships' => $this->whenLoaded('memberships', fn () => $this->memberships->map(fn ($membership) => [
                'id' => $membership->id,
                'membership_kind' => $membership->membership_kind,
                'is_primary' => $membership->is_primary,
                'group' => [
                    'id' => $membership->group->id,
                    'code' => $membership->group->code,
                    'name' => $membership->group->name,
                    'group_type' => $membership->group->type->code,
                    'group_type_id' => $membership->group->group_type_id,
                ],
            ])),
            'effective_features' => $this->when($this->resource->getAttribute('effective_features') !== null, $this->resource->getAttribute('effective_features')),
            'effective_permissions' => $this->when($this->resource->getAttribute('effective_permissions') !== null, $this->resource->getAttribute('effective_permissions')),
            'effective_access_explanation' => $this->when($this->resource->getAttribute('effective_access_explanation') !== null, $this->resource->getAttribute('effective_access_explanation')),
            'role_assignments' => $this->whenLoaded('roleAssignments'),
            'feature_suspensions' => $this->whenLoaded('featureSuspensions'),
            'membership_change_sets' => $this->when($this->resource->getAttribute('membership_change_sets') !== null, $this->resource->getAttribute('membership_change_sets')),
            'field_authorities' => $this->when($this->resource->getAttribute('field_authorities') !== null, $this->resource->getAttribute('field_authorities')),
            // last_login_atのような一般的な日時はシステムのデフォルトタイムゾーンのオフセットで
            // 送信し、画面表示では本人のタイムゾーン(timezone)に変換して表示する
            // (docs/03-architecture.md 3.4)。
            'last_login_at' => LocalDateTime::toIso8601($this->last_login_at, SystemSetting::current()->default_timezone),
        ];
    }
}
