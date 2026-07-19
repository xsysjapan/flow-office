<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OauthAuthCode extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'oauth_client_id',
        'mcp_user_id',
        'scopes',
        'redirect_uri',
        'code_challenge',
        'code_challenge_method',
        'expires_at',
        'revoked',
    ];

    protected $casts = [
        'scopes' => 'array',
        'expires_at' => 'datetime',
        'revoked' => 'boolean',
    ];
}
