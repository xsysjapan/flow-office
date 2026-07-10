<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RequestTypeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'form_schema' => $this->form_schema,
            'requires_backoffice_task' => $this->requires_backoffice_task,
            'backoffice_task_type' => $this->backoffice_task_type,
            'is_active' => $this->is_active,
        ];
    }
}
