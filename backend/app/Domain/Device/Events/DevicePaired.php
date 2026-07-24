<?php

namespace App\Domain\Device\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class DevicePaired extends ShouldBeStored
{
    /**
     * @param  array<int, string>  $abilities
     */
    public function __construct(
        public readonly array $abilities,
        public readonly string $pairedAt,
    ) {}
}
