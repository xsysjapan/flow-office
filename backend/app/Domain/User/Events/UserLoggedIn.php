<?php

namespace App\Domain\User\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * user.logged_in (UC-001: Microsoft SSOでログインする)。last_login_atは
 * ユーザー個別のタイムゾーンではなくシステムのデフォルトタイムゾーンで記録する
 * (docs/03-architecture.md 3.4)ため、イベント記録時刻のメタデータ(createdAt)ではなく
 * この専用フィールドで明示的に持つ。
 */
class UserLoggedIn extends ShouldBeStored
{
    public function __construct(
        public readonly bool $wasFirstLogin,
        public readonly string $loggedInAt,
    ) {}
}
