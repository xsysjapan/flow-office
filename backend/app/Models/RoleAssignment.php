<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['id', 'subject_type', 'subject_id', 'role_id', 'scope_type', 'scope_group_id', 'include_descendants', 'starts_at', 'ends_at', 'status', 'assigned_by'])]
class RoleAssignment extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['include_descendants' => 'boolean', 'starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function scopeGroup(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'scope_group_id');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
