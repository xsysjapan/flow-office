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
 * roles (アプリ独自の権限) や timezone は同期で上書きしない。timezoneは新規作成時のみ
 * システム設定のデフォルトタイムゾーンで設定する (docs/06-usecases-auth.md UC-003)。
 * hire_date (入社日) とtermination_date (退社日) はMS365に対応する属性がないため同期対象外で、管理者が個別に設定する
 * (docs/09-usecases-paid-leave.md UC-P002: 継続勤務期間の計算に使う)。
 */
#[Fillable(['entra_user_id', 'name', 'email', 'department', 'job_title', 'employment_status', 'timezone', 'hire_date', 'termination_date', 'last_login_at'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'hire_date' => 'date',
            'termination_date' => 'date',
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
