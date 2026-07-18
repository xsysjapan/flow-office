<?php

namespace App\Domain\Device\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class WarnStaleDevices implements Command
{
    public function __construct(public readonly int $staleAfterHours = 48) {}
}
