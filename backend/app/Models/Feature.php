<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'parent_feature_id', 'status'])]
class Feature extends Model
{
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_feature_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_feature_id');
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'group_feature_assignments');
    }
}
