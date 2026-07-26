<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExpenseRouteTemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'scope' => $this->scope,
            'employee_id' => $this->employee_id,
            'name' => $this->name,
            'origin' => $this->origin,
            'destination' => $this->destination,
            'transport_type' => $this->transport_type,
            'amount' => $this->amount,
            'category_id' => $this->category_id,
            'created_by' => $this->created_by,
            'is_active' => $this->is_active,
        ];
    }
}
