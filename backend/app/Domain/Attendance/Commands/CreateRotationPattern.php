<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class CreateRotationPattern implements Command
{
    /**
     * @param  list<array{sequence: int, shift_pattern_id: string}>  $items
     */
    public function __construct(
        public readonly string $workStyleId,
        public readonly string $name,
        public readonly array $items,
        public readonly string $createdByUserId,
    ) {}
}
