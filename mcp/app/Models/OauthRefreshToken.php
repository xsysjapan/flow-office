<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OauthRefreshToken extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'oauth_access_token_id',
        'expires_at',
        'revoked',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'revoked' => 'boolean',
    ];
}
