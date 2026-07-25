<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class UpdateWorkStyle implements Command
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public readonly string $workStyleId,
        public readonly array $attributes,
        public readonly string $updatedByUserId,
    ) {}
}
