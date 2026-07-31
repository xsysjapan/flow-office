<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceSubmissionReminderExclusionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ]),
            'year_month' => $this->year_month,
            'reason' => $this->reason,
            'excluded_by_user_id' => $this->excluded_by_user_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
