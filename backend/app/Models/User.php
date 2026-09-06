<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * アプリ側のユーザー。認証は原則Microsoft Entra ID SSOだが、初回オンボーディング
 * (docs/06-usecases-auth.md UC-000)でSSOを設定しなかった場合に限り、ローカルパスワード
 * ログイン(`POST /auth/local-login`)を許可する。SSOでログインするユーザーは`password`が
 * null のまま。Microsoft Entra ID等の外部識別子はExternalIdentityで管理する。
 * 項目の同期可否はFieldAuthorityに従い、Feature・Role・Permissionやtimezoneは同期で
 * 上書きしない。timezoneは新規作成時のみ
 * システム設定のデフォルトタイムゾーンで設定する (docs/06-usecases-auth.md UC-003)。
 * hire_date (入社日) とtermination_date (退社日) はMS365に対応する属性がないため同期対象外で、管理者が個別に設定する
 * (docs/09-usecases-paid-leave.md UC-P002: 継続勤務期間の計算に使う)。
 * usage_start_date (利用開始日) は、新規ユーザー作成時(SSO初回ログイン・オンボーディング・
 * MS365同期での新規作成)にそのユーザー行が作成された日をデフォルト値として自動設定する
 * (未設定のままだと在籍月より前の月にも督促が送られてしまうバグがあったため)。管理者は
 * 実際の入社日・利用開始日に個別に上書き修正できる(既存値がある場合、MS365同期での
 * 上書きは行わない)。勤怠未提出フォロー等の各種フォロー通知は、この日付および入社日より
 * 前の期間については送らない(まだ本システムの利用や在籍を開始していないため)。
 *
 * 主キーはUUID(HasUuids)。DB採番だと集約IDがINSERTするまで確定せずProjectorで作成できないため、
 * コマンド側で生成できるUUIDにしている(.claude/skills/add-projection「集約ルートのUUID化」参照)。
 * この行自体もUserProjectorがstored_eventsから作成・更新する
 * (docs/29-event-sourcing-framework-migration.md参照)。
 */
#[Fillable(['id', 'entra_user_id', 'name', 'email', 'employee_number', 'account_status', 'source_type', 'password', 'department', 'job_title', 'employment_status', 'timezone', 'hire_date', 'termination_date', 'usage_start_date', 'last_login_at'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuids, Notifiable;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * マイグレーションのdefault(true)と一致させる。DB側のdefaultだけでは、
     * create()直後のモデルインスタンス(未リフレッシュ)にその値が反映されないため。
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'paid_leave_auto_grant_enabled' => true,
        'special_leave_auto_grant_enabled' => true,
    ];

    /** @var list<string> */
    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'hire_date' => 'date',
            'termination_date' => 'date',
            'usage_start_date' => 'date',
            'last_login_at' => 'datetime',
            'paid_leave_auto_grant_enabled' => 'boolean',
            'special_leave_auto_grant_enabled' => 'boolean',
            // ローカルパスワードは平文でDBに保持しない (Laravel標準のhashedキャストで自動ハッシュ化する)。
            'password' => 'hashed',
        ];
    }

    /**
     * @return HasMany<AuthenticationKey, $this>
     */
    public function authenticationKeys(): HasMany
    {
        return $this->hasMany(AuthenticationKey::class);
    }

    public function externalIdentities(): HasMany
    {
        return $this->hasMany(ExternalIdentity::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'memberships')->withPivot(['membership_kind', 'is_primary']);
    }

    public function roleAssignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class, 'subject_id')->where('subject_type', 'user');
    }

    public function featureSuspensions(): HasMany
    {
        return $this->hasMany(UserFeatureSuspension::class);
    }
}
