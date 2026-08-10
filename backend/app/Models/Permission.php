<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;

#[Fillable(['code', 'resource', 'action', 'description'])]
class Permission extends Model
{
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    /** @return list<string> */
    public function allowedScopeTypes(): array
    {
        return DB::table('permission_scope_types')
            ->where('permission_id', $this->id)
            ->pluck('scope_type')
            ->all();
    }
}
