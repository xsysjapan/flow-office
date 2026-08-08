<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'provider', 'external_tenant_id', 'external_subject_id', 'external_code', 'email', 'status', 'linked_at', 'last_synced_at'])]
class ExternalIdentity extends Model
{
    protected function casts(): array
    {
        return ['linked_at' => 'datetime', 'last_synced_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
