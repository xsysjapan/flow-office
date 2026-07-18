<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceImportItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'work_date' => $this->work_date?->toDateString(),
            'proposed_data' => $this->proposed_data_json,
            'existing_data' => $this->existing_data_json,
            'differences' => $this->differences_json,
            'confidence' => $this->confidence,
            'status' => $this->status,
            'has_blocking_differences' => $this->hasBlockingDifferences(),
        ];
    }
}
