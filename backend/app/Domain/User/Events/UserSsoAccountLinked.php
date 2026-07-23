<?php

namespace App\Domain\User\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * user.sso_account_linked (UC-004: ローカルユーザーがMicrosoft 365アカウントと連携する)
 */
class UserSsoAccountLinked extends ShouldBeStored
{
    public function __construct(
        public readonly string $entraUserId,
    ) {}
}
