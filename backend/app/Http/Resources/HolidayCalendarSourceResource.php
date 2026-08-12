<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HolidayCalendarSourceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'source_kind' => $this->source_kind,
            'ics_url' => $this->ics_url,
            'uploaded_ics_filename' => $this->uploaded_ics_filename,
            'sync_status' => $this->sync_status,
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
            'last_error' => $this->last_error,
            'last_sync_summary' => $this->last_sync_summary,
            'disabled_at' => $this->disabled_at?->toIso8601String(),
        ];
    }
}
