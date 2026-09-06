<?php

namespace App\Domain\UserManagement\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * user.paid_leave_auto_grant_enabled_set
 */
class PaidLeaveAutoGrantEnabledSet extends ShouldBeStored
{
    public function __construct(
        public readonly bool $enabled,
        public readonly string $changedByUserId,
    ) {}
}
