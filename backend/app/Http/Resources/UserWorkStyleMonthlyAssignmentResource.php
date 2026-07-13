<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserWorkStyleMonthlyAssignmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'year_month' => $this->year_month,
            'work_style_id' => $this->work_style_id,
            'work_style' => $this->whenLoaded('workStyle', fn () => [
                'id' => $this->workStyle->id,
                'code' => $this->workStyle->code,
                'name' => $this->workStyle->name,
            ]),
            'assigned_by_user_id' => $this->assigned_by_user_id,
        ];
    }
}
