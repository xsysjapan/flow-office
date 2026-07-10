<?php

namespace App\Domain\User;

use App\Domain\EventSourcing\EventStore;
use App\Domain\User\Events\UserLoggedIn;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\LocalDateTime;
use Laravel\Socialite\Contracts\User as SocialiteUser;

/**
 * UC-001: Microsoft SSOでログインする。
 * Entra IDのユーザーID・メール・表示名を取得し、初回ログインならアプリ側ユーザーを作成、
 * 既存ユーザーなら最終ログイン日時を更新する。
 */
class SsoAuthenticator
{
    public function __construct(private readonly EventStore $eventStore) {}

    public function handle(SocialiteUser $ssoUser): User
    {
        $user = User::query()->where('entra_user_id', $ssoUser->getId())->first();
        $wasFirstLogin = $user === null;

        // last_login_atのような一般的な日時はユーザー個別のタイムゾーンではなく、
        // システムのデフォルトタイムゾーンで記録する (docs/03-architecture.md 3.4)。
        $defaultTimezone = SystemSetting::current()->default_timezone;

        if ($user === null) {
            $user = User::query()->create([
                'entra_user_id' => $ssoUser->getId(),
                'name' => $ssoUser->getName() ?? $ssoUser->getNickname() ?? $ssoUser->getEmail(),
                'email' => $ssoUser->getEmail(),
                'employment_status' => 'active',
                'timezone' => $defaultTimezone,
                'last_login_at' => LocalDateTime::now($defaultTimezone),
            ]);

            $user->roles()->attach(Role::query()->where('code', Role::EMPLOYEE)->first());
        } else {
            $user->last_login_at = LocalDateTime::now($defaultTimezone);
            $user->save();
        }

        $this->eventStore->append(
            aggregateType: 'user',
            aggregateId: (string) $user->id,
            event: new UserLoggedIn(userId: $user->id, wasFirstLogin: $wasFirstLogin),
        );

        return $user;
    }
}
