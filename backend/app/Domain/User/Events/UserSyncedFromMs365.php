<?php

namespace App\Domain\User\Events;

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
    ) {}
}
