<?php

namespace App\Http\Resources;

use App\Support\FrontendUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 備品(貸出品・設置品)の一覧・詳細レスポンス整形。フェーズ3(API層)実装対象。
 */
class AssetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'asset_no' => $this->asset_no,
            'name' => $this->name,
            'category' => $this->category,
            'serial_number' => $this->serial_number,
            'management_type' => $this->management_type,
            'lending_status' => $this->lending_status,
            'installation_status' => $this->installation_status,
            'lending_method' => $this->lending_method,
            'default_location_text' => $this->default_location_text,
            'qr_token' => $this->qr_token,
            'qr_url' => FrontendUrl::path("/assets/qr/{$this->qr_token}"),
            'current_loan_id' => $this->current_loan_id,
            'notes' => $this->notes,
            'current_loan' => $this->when($this->current_loan_id !== null, function () {
                $loan = $this->relationLoaded('loans')
                    ? $this->loans->firstWhere('id', $this->current_loan_id)
                    : $this->loans()->whereKey($this->current_loan_id)->first();

                return $loan !== null ? new AssetLoanResource($loan) : null;
            }),
            'current_placement' => $this->when($this->management_type === 'installation', function () {
                $placement = $this->currentPlacement();

                return $placement !== null ? [
                    'location_text' => $placement->location_text,
                    'started_at' => $placement->started_at?->toIso8601String(),
                ] : null;
            }),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
