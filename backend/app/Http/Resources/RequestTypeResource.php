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
            'requires_attachment' => $this->requires_attachment,
            'attachment_max_size_kb' => $this->attachment_max_size_kb,
            'attachment_allowed_extensions' => $this->attachment_allowed_extensions,
            'eligible_role_codes' => $this->eligible_role_codes,
            'requires_backoffice_task' => $this->requires_backoffice_task,
            'backoffice_task_type' => $this->backoffice_task_type,
            'backoffice_department' => $this->backoffice_department,
            'export_amount_field' => $this->export_amount_field,
            'allowed_status_transitions' => $this->allowed_status_transitions,
            'is_active' => $this->is_active,
        ];
    }
}
