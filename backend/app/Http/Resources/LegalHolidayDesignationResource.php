<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LegalHolidayDesignationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'week_start_date' => $this->week_start_date?->toDateString(),
            'designated_date' => $this->designated_date?->toDateString(),
            'reason' => $this->reason,
            'designated_by' => $this->designated_by,
        ];
    }
}
