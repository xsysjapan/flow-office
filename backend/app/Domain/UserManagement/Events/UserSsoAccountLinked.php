<?php

namespace App\Domain\UserManagement\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * user.sso_account_linked (UC-004: ローカルユーザーがMicrosoft 365アカウントと連携する)
 */
class UserSsoAccountLinked extends ShouldBeStored
{
    public function __construct(
        public readonly string $entraUserId,
        /** Entra IDのテナントID(記録時のsystem_settings.m365_tenant_id)。旧イベントはnull(Projectorは設定値を読む)。 */
        public readonly ?string $externalTenantId = null,
    ) {}
}
