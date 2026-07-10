<?php

namespace App\Domain\PaidLeave\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-P005: 有給消滅警告を出す(バッチ)。
 */
class WarnExpiringPaidLeave implements Command
{
    public function __construct(
        public readonly ?string $asOf = null,
    ) {}
}
