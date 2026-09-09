<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read \App\Models\User $resource
 */
class PaidLeaveGrantRuleTargetUserResource extends JsonResource
{
    public function __construct($resource, private readonly ?string $workStyleName)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'work_style' => $this->workStyleName,
            'paid_leave_auto_grant_enabled' => $this->paid_leave_auto_grant_enabled,
        ];
    }
}
