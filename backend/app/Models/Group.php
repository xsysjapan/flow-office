<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['id', 'group_type_id', 'name', 'code', 'description', 'parent_group_id', 'status'])]
class Group extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    public function type(): BelongsTo
    {
        return $this->belongsTo(GroupType::class, 'group_type_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_group_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_group_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'memberships')->withPivot(['membership_kind', 'is_primary']);
    }

    public function features(): BelongsToMany
    {
        return $this->belongsToMany(Feature::class, 'group_feature_assignments');
    }

    public function roleAssignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class, 'subject_id')->where('subject_type', 'group');
    }
}
