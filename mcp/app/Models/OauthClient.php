<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OauthClient extends Model
{
    protected $fillable = [
        'client_id',
        'client_name',
        'redirect_uris',
        'grant_types',
        'token_endpoint_auth_method',
    ];

    protected $casts = [
        'redirect_uris' => 'array',
        'grant_types' => 'array',
    ];
}
