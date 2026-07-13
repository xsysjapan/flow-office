<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RotationPatternResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'work_style_id' => $this->work_style_id,
            'name' => $this->name,
            'cycle_length' => $this->cycle_length,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'sequence' => $item->sequence,
                'shift_pattern_id' => $item->shift_pattern_id,
                'shift_pattern_name' => $item->shiftPattern?->name,
                'shift_pattern_code' => $item->shiftPattern?->code,
            ])),
        ];
    }
}
