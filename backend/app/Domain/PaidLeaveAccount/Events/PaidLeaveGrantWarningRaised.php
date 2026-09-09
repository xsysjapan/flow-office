<?php

namespace App\Domain\PaidLeaveAccount\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class PaidLeaveGrantWarningRaised extends ShouldBeStored
{
    public function __construct(
        public readonly string $grantId,
        public readonly string $warningType,
        public readonly string $message,
    ) {}
}
