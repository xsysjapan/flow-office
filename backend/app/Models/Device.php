<?php

namespace App\Models;

use Database\Factories\DeviceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * 端末マスタ(docs/23-usecases-devices.md、docs/16-database-schema.md)。
 * 共有Android打刻リーダー・個人端末・外部端末を共通のモデルで扱う。打刻専用のエンティティに
 * せず、`device_roles`で役割を表現する(CLAUDE.mdの設計原則12)。
 *
 * Sanctumの`HasApiTokens`はUser以外の任意のEloquentモデルにも適用できるため、端末自身に
 * Sanctumトークンを発行する(UC-D002)。`auth:sanctum`ミドルウェアから認証主体として
 * 解決されるよう、Userと同様にIlluminate\Foundation\Auth\Userを継承する。
 */
#[Fillable([
    'owner_type', 'owner_user_id', 'activated_by_user_id', 'name', 'device_type', 'status', 'site_id',
    'location_name', 'default_work_location_type', 'timezone', 'allowed_punch_types', 'allow_offline',
    'require_location', 'auto_detect_punch_type', 'last_seen_at', 'app_version',
    'paired_at', 'disabled_at', 'revoked_at',
])]
class Device extends Authenticatable
{
    /** @use HasFactory<DeviceFactory> */
    use HasApiTokens, HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'allowed_punch_types' => 'array',
            'allow_offline' => 'boolean',
            'require_location' => 'boolean',
            'auto_detect_punch_type' => 'boolean',
            'last_seen_at' => 'datetime',
            'paired_at' => 'datetime',
            'disabled_at' => 'datetime',
            'revoked_at' => 'datetime',
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
     * ペアリング用一時トークンを発行した管理者(UC-D002)。管理者ICカードの初回登録
     * (ブートストラップ)時に、自分自身を登録できるかどうかの判定に使う。
     *
     * @return BelongsTo<User, $this>
     */
    public function activatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by_user_id');
    }

    /**
     * @return HasMany<DeviceRole, $this>
     */
    public function roles(): HasMany
    {
        return $this->hasMany(DeviceRole::class);
    }

    /**
     * @return HasMany<DeviceScope, $this>
     */
    public function scopes(): HasMany
    {
        return $this->hasMany(DeviceScope::class);
    }

    public function hasRole(string $roleType): bool
    {
        return $this->roles->contains('role_type', $roleType);
    }

    /**
     * このデバイスが持つ役割・端末スコープから発行すべきSanctumトークンのabilityを決定する
     * (docs/23-usecases-devices.md UC-D002「最小権限」)。役割由来のability(recorder:punch等)に
     * 加えて、外部端末に個別付与された`device_scopes`(attendance:clock等)も合成する。
     *
     * @return array<int, string>
     */
    public function tokenAbilities(): array
    {
        $abilities = [];
        foreach ($this->roles as $role) {
            $abilities = [...$abilities, ...DeviceRoleType::abilitiesFor($role->role_type)];
        }
        foreach ($this->scopes as $scope) {
            $abilities[] = $scope->scope;
        }

        return array_values(array_unique($abilities));
    }
}
