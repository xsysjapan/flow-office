<?php

namespace App\Domain\UserManagement\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * user.usage_start_date_set
 */
class UserUsageStartDateSet extends ShouldBeStored
{
    public function __construct(
        public readonly string $usageStartDate,
        public readonly string $changedByUserId,
    ) {}
}
