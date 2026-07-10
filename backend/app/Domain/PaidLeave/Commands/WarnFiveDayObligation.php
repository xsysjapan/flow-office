<?php

namespace App\Domain\PaidLeave\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-P006: 年5日取得義務を警告する(バッチ)。
 */
class WarnFiveDayObligation implements Command
{
    public function __construct(
        public readonly ?string $asOf = null,
    ) {}
}
