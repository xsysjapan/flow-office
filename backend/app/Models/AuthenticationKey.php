<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 認証キー(docs/24-usecases-authentication-keys.md)。NFCカードのUID・生体認証端末の
 * 外部利用者ID・QR・FIDO等をユーザーに紐付ける。生の値は保存せず`key_hash`のみ保存する
 * (CLAUDE.mdの設計原則12、生体情報そのものを保存しない)。
 */
#[Fillable([
    'user_id', 'key_type', 'display_name', 'key_hash', 'status', 'valid_from', 'valid_until',
    'metadata_json', 'registered_by_user_id', 'registered_at', 'disabled_at',
])]
class AuthenticationKey extends Model
{
    protected function casts(): array
    {
        return [
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'metadata_json' => 'array',
            'registered_at' => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<AuthenticationKeyDeviceRule, $this>
     */
    public function deviceRules(): HasMany
    {
        return $this->hasMany(AuthenticationKeyDeviceRule::class);
    }

    public function isUsableNow(): bool
    {
        if ($this->status !== AuthenticationKeyStatus::ACTIVE) {
            return false;
        }

        $now = now();
        if ($this->valid_from !== null && $now->lt($this->valid_from)) {
            return false;
        }

        if ($this->valid_until !== null && $now->gt($this->valid_until)) {
            return false;
        }

        return true;
    }

    public function isUsableOnDevice(?int $deviceId): bool
    {
        $rules = $this->deviceRules;
        if ($rules->isEmpty()) {
            return true;
        }

        $applicable = $rules->filter(fn (AuthenticationKeyDeviceRule $rule) => $rule->device_id === null || $rule->device_id === $deviceId);
        if ($applicable->isEmpty()) {
            return true;
        }

        return $applicable->contains(fn (AuthenticationKeyDeviceRule $rule) => $rule->allow);
    }
}
