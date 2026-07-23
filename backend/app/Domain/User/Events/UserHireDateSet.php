<?php

namespace App\Domain\User\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * user.hire_date_set
 */
class UserHireDateSet extends ShouldBeStored
{
    public function __construct(
        public readonly string $hireDate,
        public readonly string $changedByUserId,
    ) {}
}
