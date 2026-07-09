<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * アプリ側のユーザー。認証はMicrosoft Entra ID SSOのみ(ローカルパスワードは持たない)。
 * name/email/department/job_title/employment_status はMS365同期(UC-002)で更新されるが、
 * roles (アプリ独自の権限) は同期で上書きしない。
 */
#[Fillable(['entra_user_id', 'name', 'email', 'department', 'job_title', 'employment_status', 'last_login_at'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    public function hasRole(string $code): bool
    {
        return $this->roles->contains('code', $code);
    }
}
