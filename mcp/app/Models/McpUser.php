<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class McpUser extends Model
{
    protected $fillable = [
        'email',
        'display_name',
    ];

    public function backendToken(): HasOne
    {
        return $this->hasOne(McpUserBackendToken::class);
    }
}
