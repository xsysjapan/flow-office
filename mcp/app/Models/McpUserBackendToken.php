<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * backend/ の個人連携Sanctumトークン(UC-I001で発行)を暗号化して保持する。
 * mcp/はこのトークンを使ってbackend APIを呼び出すだけで、backendのDBには触れない。
 */
class McpUserBackendToken extends Model
{
    protected $fillable = [
        'mcp_user_id',
        'encrypted_token',
        'granted_scopes',
        'last_used_at',
    ];

    protected $casts = [
        'granted_scopes' => 'array',
        'last_used_at' => 'datetime',
    ];

    public function setPlainToken(string $token): void
    {
        $this->encrypted_token = Crypt::encryptString($token);
    }

    public function getPlainToken(): string
    {
        return Crypt::decryptString($this->encrypted_token);
    }
}
