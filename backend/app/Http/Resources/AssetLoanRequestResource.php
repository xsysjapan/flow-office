<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 貸出申請Projection(asset_loan_requests)のレスポンス整形。
 * spec 論点2-3: approval方式の貸与時に「承認済み・未貸与の申請」から1件選ばせるUIで使う。
 */
class AssetLoanRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'asset_id' => $this->asset_id,
            'applicant_user_id' => $this->applicant_user_id,
            'applicant' => new UserResource($this->whenLoaded('applicant')),
            'approver_user_id' => $this->approver_user_id,
            'approver' => new UserResource($this->whenLoaded('approver')),
            'status' => $this->status,
            'purpose' => $this->purpose,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'rejected_at' => $this->rejected_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            'withdrawn_at' => $this->withdrawn_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'lent_at' => $this->lent_at?->toIso8601String(),
        ];
    }
}
