<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeRotationAssignmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'rotation_pattern_id' => $this->rotation_pattern_id,
            'rotation_pattern_name' => $this->whenLoaded('rotationPattern', fn () => $this->rotationPattern->name),
            'rotation_start_date' => $this->rotation_start_date?->toDateString(),
            'rotation_start_position' => $this->rotation_start_position,
        ];
    }
}
