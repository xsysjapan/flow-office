<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'display_order', 'is_system', 'status', 'membership_limit_type', 'max_memberships_per_user', 'requires_primary_membership', 'max_primary_memberships'])]
class GroupType extends Model
{
    protected function casts(): array
    {
        return ['is_system' => 'boolean', 'requires_primary_membership' => 'boolean'];
    }

    public function groups(): HasMany
    {
        return $this->hasMany(Group::class);
    }
}
