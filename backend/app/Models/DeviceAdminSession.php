<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 端末が管理者モードに入っている期間(docs/23-usecases-devices.md UC-D006)。
 */
#[Fillable([
    'device_id', 'admin_user_id', 'authentication_key_id', 'source',
    'started_at', 'expires_at', 'ended_at',
])]
class DeviceAdminSession extends Model
{
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }

    /**
     * @return BelongsTo<AuthenticationKey, $this>
     */
    public function authenticationKey(): BelongsTo
    {
        return $this->belongsTo(AuthenticationKey::class);
    }

    public function isActive(): bool
    {
        return $this->ended_at === null && $this->expires_at->isFuture();
    }

    public static function activeForDevice(int $deviceId): ?self
    {
        return self::query()
            ->where('device_id', $deviceId)
            ->whereNull('ended_at')
            ->where('expires_at', '>', Carbon::now())
            ->orderByDesc('started_at')
            ->first();
    }
}
