<?php

namespace App\Domain\AuthenticationKey\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-K003: 認証キーを無効化する。
 */
class DisableAuthenticationKey implements Command
{
    public function __construct(
        public readonly string $authenticationKeyId,
        public readonly string $disabledByUserId,
    ) {}
}
