<?php

namespace App\Domain\User\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-001: Microsoft SSOでログインする。
 */
class RecordSsoLogin implements Command
{
    public function __construct(
        public readonly string $entraUserId,
        public readonly ?string $name,
        public readonly ?string $email,
    ) {}
}
