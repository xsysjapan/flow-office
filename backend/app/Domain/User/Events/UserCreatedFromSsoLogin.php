<?php

namespace App\Domain\User\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * user.created_from_sso_login(UC-001: Microsoft SSOでログインする)。entra_user_idにも
 * emailにも一致する既存ユーザーが無かった場合の初回ログインで、新規に社員(employeeロール)
 * ユーザーを作成する。初回オンボーディング(UC-000)の管理者作成(UserOnboardedAsAdmin)とは
 * 別イベント(ロール付与が異なるため)。
 */
class UserCreatedFromSsoLogin extends ShouldBeStored
{
    public function __construct(
        public readonly string $entraUserId,
        public readonly string $name,
        public readonly string $email,
    ) {}
}
