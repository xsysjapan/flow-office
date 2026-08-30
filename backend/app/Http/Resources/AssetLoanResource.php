<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssetLoanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'asset_id' => $this->asset_id,
            'user_id' => $this->user_id,
            'borrower' => new UserResource($this->whenLoaded('borrower')),
            'loan_request_id' => $this->loan_request_id,
            'loaned_at' => $this->loaned_at?->toIso8601String(),
            'expected_return_at' => $this->expected_return_at?->toIso8601String(),
            'loaned_by_user_id' => $this->loaned_by_user_id,
            'returned_at' => $this->returned_at?->toIso8601String(),
            'returned_by_user_id' => $this->returned_by_user_id,
            'return_note' => $this->return_note,
        ];
    }
}
