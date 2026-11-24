<?php

namespace App\Domain\UserManagement\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * user.synced_from_ms365(UC-002)。氏名・メール・部署・役職・在籍状態のみを保持し、
 * ロール・timezoneはこのイベントで上書きしない(UserProjector参照)。
 */
class UserSyncedFromMs365 extends ShouldBeStored
{
    public function __construct(
        public readonly string $entraUserId,
        public readonly string $name,
        public readonly ?string $email,
        public readonly ?string $department,
        public readonly ?string $jobTitle,
        public readonly string $employmentStatus,
        /** Entra IDのテナントID(記録時のsystem_settings.m365_tenant_id)。旧イベントはキーが無くnull(未補正。補正するまで作り直しは拒否される。Projectorは設定値を読まず、そのまま使う)。 */
        public readonly ?string $externalTenantId = null,
        /** 作成時の既定タイムゾーン(記録時のsystem_settings.default_timezone)。旧イベントはキーが無くnull(未補正。補正するまで作り直しは拒否され、Projectorは行を作るときnullなら例外にする)。 */
        public readonly ?string $timezone = null,
    ) {}
}
