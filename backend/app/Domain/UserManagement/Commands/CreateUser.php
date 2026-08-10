<?php

namespace App\Domain\UserManagement\Commands;

use App\Domain\EventSourcing\Contracts\Command;

final class CreateUser implements Command
{
    public function __construct(
        public readonly string $userId,
        public readonly array $attributes,
        public readonly string $createdByUserId,
    ) {}
}
