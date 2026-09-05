<?php

namespace App\Domain\UserManagement\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * user.special_leave_auto_grant_enabled_set
 */
class SpecialLeaveAutoGrantEnabledSet extends ShouldBeStored
{
    public function __construct(
        public readonly bool $enabled,
        public readonly string $changedByUserId,
    ) {}
}
