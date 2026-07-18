<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * 個人/組織のAPI・MCP連携(docs/25-usecases-integrations-mcp.md)。実際の認証キーの実体は
 * Sanctumの`personal_access_tokens`に委譲し、ここは「誰が・どの用途で・いつ発行したか」の
 * 台帳として機能する(devices/authentication_keysと同じ考え方)。
 */
#[Fillable([
    'owner_type', 'owner_user_id', 'client_type', 'client_name', 'purpose',
    'personal_access_token_id', 'status', 'last_used_at', 'expires_at', 'registered_by_user_id',
])]
class ApplicationIntegration extends Model
{
    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * @return BelongsTo<PersonalAccessToken, $this>
     */
    public function personalAccessToken(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class);
    }

    /**
     * @return HasMany<IntegrationScope, $this>
     */
    public function scopes(): HasMany
    {
        return $this->hasMany(IntegrationScope::class, 'integration_id');
    }
}
